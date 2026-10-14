<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\OtpRequest;
use App\Http\Requests\PasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Mail\LoginCodeMail;
use App\Models\AuditLog;
use App\Models\LoginChallenge;
use App\Models\LoginLockout;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $input = $request->validated();
        if ($seconds = LoginLockout::secondsLeft($input['email'])) {
            return $this->lockedResponse($seconds);
        }
        $user = User::with(['roles.permissions', 'permissions'])->where('email', $input['email'])->first();
        if (! $user || ! Hash::check($input['password'], $user->password)) {
            $this->audit(null, 'failed_login', ['email' => $input['email']]);
            return $this->failedAttempt($input['email']);
        }
        abort_unless($user->is_active, 403, 'This account is inactive. Contact an administrator.');
        if ($destination = $this->otpDestination($user)) {
            return $this->startChallenge($user, $destination);
        }

        return $this->signIn($user);
    }

    public function verifyOtp(OtpRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $challenge = $this->pendingChallenge($request->challenge, true);
            if (! $challenge) { return $this->expiredResponse(); }
            if ($seconds = LoginLockout::secondsLeft($challenge->user->email)) {
                return $this->lockedResponse($seconds);
            }
            if (! Hash::check($request->code, $challenge->code_hash)) {
                $challenge->increment('attempts');
                $left = LoginChallenge::MAX_ATTEMPTS - $challenge->attempts;
                $lockout = LoginLockout::recordFailure($challenge->user->email);
                $this->audit($challenge->user, 'failed_login', ['reason' => 'Incorrect sign-in code']);
                if ($lockout->locked_until) {
                    $this->lockAlert($challenge->user, $challenge->user->email, $lockout);
                    $challenge->delete();
                    return $this->lockedResponse(LoginLockout::secondsLeft($challenge->user->email));
                }
                if ($left <= 0) { $challenge->delete(); return $this->expiredResponse('Too many wrong codes. Enter your email and password again.'); }

                return response()->json(['message' => "That code is not correct. {$left} ".($left === 1 ? 'try' : 'tries').' left.', 'attempts_left' => $left, 'errors' => ['code' => ['The sign-in code is incorrect.']]], 422);
            }
            $user = $challenge->user;
            $challenge->delete();

            return $this->signIn($user);
        });
    }

    public function resendOtp(OtpRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $challenge = $this->pendingChallenge($request->challenge, true);
            if (! $challenge) { return $this->expiredResponse(); }
            if ($seconds = LoginLockout::secondsLeft($challenge->user->email)) { return $this->lockedResponse($seconds); }
            $seconds = max(0, LoginChallenge::RESEND_AFTER - (int) $challenge->last_sent_at->diffInSeconds(now()));
            if ($seconds > 0) {
                return response()->json(['message' => "Wait {$seconds} seconds before asking for another code.", 'retry_after' => $seconds], 429)->header('Retry-After', (string) $seconds);
            }
            $destination = $this->otpDestination($challenge->user);
            if (! $destination) { $challenge->delete(); return $this->expiredResponse(); }
            $code = $this->newCode();
            if (! $this->sendCode($challenge->user, $destination, $code)) {
                return response()->json(['message' => 'The sign-in code could not be sent. Try again in a moment.'], 502);
            }
            $challenge->update(['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(LoginChallenge::MINUTES), 'last_sent_at' => now()]);

            return response()->json(['sent_to' => $this->mask($destination), 'expires_in' => LoginChallenge::MINUTES * 60]);
        });
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        request()->attributes->set('audit.skip', true);
        $this->audit($request->user(), 'logout');

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function profile(Request $request)
    {
        // ai_features: the AI buttons this session may show (Settings > Automation); the server re-checks on every use.
        return response()->json([...$request->user()->toArray(), 'ai_features' => app(\App\Services\AiService::class)->enabledFeatures()]);
    }

    public function updateProfile(ProfileRequest $request)
    {
        $user = $request->user();
        request()->attributes->set('audit.entity_type', 'User');
        request()->attributes->set('audit.entity_id', $user->id);
        request()->attributes->set('audit.old_values', $user->toArray());
        $user->update($request->validated());
        request()->attributes->set('audit.new_values', $user->fresh()->toArray());

        return response()->json($user->fresh());
    }

    public function changePassword(PasswordRequest $request)
    {
        $user = $request->user();
        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }
        $user->update(['password' => $request->password]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();
        $user->loginChallenges()->delete();
        request()->attributes->set('audit.entity_type', 'User');
        request()->attributes->set('audit.entity_id', $user->id);
        request()->attributes->set('audit.action', 'password_changed');
        request()->attributes->set('audit.old_values', null);
        request()->attributes->set('audit.new_values', ['changed' => true]);

        return response()->json(['message' => 'Password changed successfully. Other sessions have been signed out.']);
    }

    private function failedAttempt(string $email)
    {
        $row = LoginLockout::recordFailure($email);
        if ($row->locked_until) {
            $this->lockAlert(null, $email, $row);
            return $this->lockedResponse(LoginLockout::secondsLeft($email));
        }
        $left = max(0, LoginLockout::FREE_ATTEMPTS - $row->failed_attempts);
        $message = 'The provided credentials are incorrect. ';
        $message .= $left > 0 ? "{$left} ".($left === 1 ? 'attempt' : 'attempts').' left before sign-in is locked.' : 'No attempts left: one more failed attempt will lock sign-in for 5 minutes.';

        return response()->json(['message' => $message, 'attempts_left' => $left], 401);
    }

    // Every lock is audited. A fresh 24-hour lock on a staff account also alerts active Admins once; shorter locks and
    // concurrent attempts that find the account already locked do not.
    private function lockAlert(?User $actor, string $email, LoginLockout $row): void
    {
        $this->audit($actor, 'account_locked', ['email' => $email, 'failed_attempts' => $row->failed_attempts]);
        $longest = ! isset(LoginLockout::LOCK_MINUTES[$row->failed_attempts]);
        $user = $longest && $row->wasChanged('locked_until') ? User::where('email', $email)->first() : null;
        if (! $user) { return; }
        foreach (User::whereHas('roles', fn ($roles) => $roles->where('name', 'Admin'))->where('is_active', true)->pluck('id') as $id) {
            Notification::create(['user_id' => $id, 'type' => 'sign_in_locked', 'title' => 'Sign-in locked for 24 hours',
                'message' => "{$user->name}'s sign-in is locked for 24 hours after repeated failed attempts. Check with them before unlocking it on Users & Roles.",
                'data' => ['user_id' => $user->id]]);
        }
    }

    private function lockedResponse(int $seconds)
    {
        $wait = $seconds >= 3600 ? ceil($seconds / 3600).' hours' : ceil($seconds / 60).' minutes';

        return response()->json(['message' => "Too many failed sign-in attempts. Sign-in is locked for {$wait}.", 'retry_after' => $seconds, 'attempts_left' => 0], 429)->header('Retry-After', (string) $seconds);
    }

    private function signIn(User $user)
    {
        abort_unless($user->is_active, 403, 'This account is inactive. Contact an administrator.');
        LoginLockout::clear($user->email);
        $token = $user->createToken('staff-session', ['staff'])->plainTextToken;
        $this->audit($user, 'login');

        return response()->json(['user' => $user, 'token' => $token]);
    }

    private function otpDestination(User $user): ?string
    {
        return match (config('sfa.login_otp')) { 'all' => $user->otp_email ?: $user->email, 'bound' => $user->otp_email ?: null, default => null };
    }

    private function startChallenge(User $user, string $destination)
    {
        LoginChallenge::where('user_id', $user->id)->orWhere('expires_at', '<', now())->delete();
        $token = Str::random(64);
        $code = $this->newCode();
        $challenge = LoginChallenge::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(LoginChallenge::MINUTES), 'last_sent_at' => now()]);
        if (! $this->sendCode($user, $destination, $code)) {
            $challenge->delete();
            return response()->json(['message' => 'The sign-in code could not be sent. Try again in a moment.'], 502);
        }

        return response()->json(['otp_required' => true, 'challenge' => $token, 'sent_to' => $this->mask($destination), 'expires_in' => LoginChallenge::MINUTES * 60]);
    }

    private function pendingChallenge(string $token, bool $lock = false): ?LoginChallenge
    {
        $query = LoginChallenge::with('user')->where('token_hash', hash('sha256', $token));
        if ($lock) { $query->lockForUpdate(); }
        $challenge = $query->first();
        if ($challenge && ($challenge->expires_at->isPast() || ! $challenge->user?->is_active)) {
            $challenge->delete();
            return null;
        }

        return $challenge;
    }

    private function newCode(): string { return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }

    private function sendCode(User $user, string $destination, string $code): bool
    {
        try { Mail::to($destination)->send(new LoginCodeMail($code, LoginChallenge::MINUTES)); }
        catch (Throwable $exception) { report($exception); $this->audit($user, 'otp_delivery_failed'); return false; }
        $this->audit($user, 'otp_sent');

        return true;
    }

    private function mask(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return substr($name, 0, 2).str_repeat('*', max(2, strlen($name) - 2)).'@'.$domain;
    }

    private function expiredResponse(string $message = 'This sign-in has expired. Enter your email and password again.')
    {
        return response()->json(['message' => $message], 410);
    }

    private function audit(?User $user, string $action, array $values = []): void
    {
        AuditLog::create(['user_id' => $user?->id, 'action' => $action, 'entity_type' => 'Auth', 'entity_id' => $user?->id, 'new_values' => $values ?: null, 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }
}
