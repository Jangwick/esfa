<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\AuditLog;
use App\Models\LoginChallenge;
use App\Models\LoginLockout;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $password = 'password', string $email = 'admin@example.com')
    {
        return $this->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
    }

    private function bearer(string $token): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_password_login_serialization_and_logout_revokes_token(): void
    {
        $this->postJson('/api/v1/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $result = $this->login('password', 'ADMIN@EXAMPLE.COM')->assertOk()->assertJsonPath('user.email', 'admin@example.com')->assertJsonPath('user.roles.0', 'Admin')->assertJsonMissingPath('user.password')->json();
        $this->assertContains('manage integrations', $result['user']['permissions']);
        $this->getJson('/api/v1/user', $this->bearer($result['token']))->assertOk()->assertJsonPath('roles.0', 'Admin');
        $this->postJson('/api/v1/logout', [], $this->bearer($result['token']))->assertOk();
        $this->getJson('/api/v1/user', $this->bearer($result['token']))->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'entity_type' => 'Auth']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'entity_type' => 'Auth']);
    }

    public function test_five_free_failures_then_escalating_lockouts_refuse_even_correct_password(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login('incorrect')->assertUnauthorized()->assertJsonPath('attempts_left', 5 - $attempt);
        }
        $sixth = $this->login('incorrect')->assertStatus(429)->assertJsonPath('retry_after', 300);
        $sixth->assertHeader('Retry-After', '300');
        $this->login()->assertStatus(429);
        $this->assertSame(6, LoginLockout::first()->failed_attempts);
        $this->travel(301)->seconds();
        $this->login('incorrect')->assertStatus(429)->assertJsonPath('retry_after', 600)->assertHeader('Retry-After', '600');
        $this->travel(601)->seconds();
        $this->login('incorrect')->assertStatus(429)->assertJsonPath('retry_after', 86400)->assertHeader('Retry-After', '86400');
        $this->assertSame(8, LoginLockout::first()->failed_attempts);
    }

    // ADM-07
    public function test_the_24_hour_lock_alerts_active_admins_once_and_shorter_locks_do_not(): void
    {
        $finance = User::where('email', 'finance@example.com')->firstOrFail();
        $admins = User::role('Admin')->where('is_active', true)->orderBy('id')->pluck('id')->all();
        $alerts = fn () => Notification::where('type', 'sign_in_locked')->orderBy('user_id')->get();
        for ($attempt = 1; $attempt <= 6; $attempt++) { $this->login('incorrect', $finance->email); }
        $this->travel(301)->seconds();
        $this->login('incorrect', $finance->email)->assertStatus(429)->assertJsonPath('retry_after', 600);
        $this->assertCount(0, $alerts(), 'The 5- and 10-minute locks are only audited.');
        $this->assertSame(2, AuditLog::where('action', 'account_locked')->count());

        $this->travel(601)->seconds();
        $this->login('incorrect', $finance->email)->assertStatus(429)->assertJsonPath('retry_after', 86400);
        $this->assertNotEmpty($admins);
        $this->assertSame($admins, $alerts()->pluck('user_id')->all(), 'Each active Admin is alerted.');
        $this->assertSame(['user_id' => $finance->id], $alerts()->first()->data);
        $this->login('incorrect', $finance->email)->assertStatus(429);
        $this->login('password', $finance->email)->assertStatus(429);
        $this->assertCount(count($admins), $alerts(), 'Attempts during the lock do not alert again.');

        LoginLockout::create(['email' => 'nobody@example.com', 'failed_attempts' => 7, 'locked_until' => now()->subMinute(), 'last_failed_at' => now()->subMinutes(11)]);
        $this->login('incorrect', 'nobody@example.com')->assertStatus(429)->assertJsonPath('retry_after', 86400);
        $this->assertCount(count($admins), $alerts(), 'An email with no staff account is locked without an alert.');
    }

    public function test_a_lock_reached_through_wrong_sign_in_codes_is_audited_and_alerted(): void
    {
        config(['sfa.login_otp' => 'all']);
        Mail::fake();
        $result = $this->login('password', 'finance@example.com')->json();
        LoginChallenge::first()->update(['code_hash' => Hash::make('123456')]);
        LoginLockout::create(['email' => 'finance@example.com', 'failed_attempts' => 7, 'locked_until' => now()->subMinute(), 'last_failed_at' => now()->subMinutes(11)]);
        $this->postJson('/api/v1/login/otp', ['challenge' => $result['challenge'], 'code' => '000000'])->assertStatus(429)->assertJsonPath('retry_after', 86400);
        $finance = User::where('email', 'finance@example.com')->firstOrFail();
        $this->assertSame(['email' => 'finance@example.com', 'failed_attempts' => 8], AuditLog::where('action', 'account_locked')->sole()->new_values);
        $this->assertSame($finance->id, AuditLog::where('action', 'account_locked')->sole()->user_id);
        $this->assertSame(User::role('Admin')->where('is_active', true)->count(), Notification::where('type', 'sign_in_locked')->count());
    }

    public function test_success_and_quiet_day_reset_failure_counts_case_insensitively(): void
    {
        $this->login('bad', 'ADMIN@EXAMPLE.COM')->assertUnauthorized()->assertJsonPath('attempts_left', 4);
        $this->login()->assertOk();
        $this->assertDatabaseCount('login_lockouts', 0);
        $this->login('bad')->assertUnauthorized();
        $this->travel(25)->hours();
        $this->login('bad')->assertUnauthorized()->assertJsonPath('attempts_left', 4);
    }

    public function test_inactive_user_cannot_login_or_use_an_existing_session(): void
    {
        $user = User::where('email', 'admin@example.com')->first();
        $token = $user->createToken('previous', ['staff'])->plainTextToken;
        $user->update(['is_active' => false]);
        $this->login()->assertForbidden();
        $this->getJson('/api/v1/user', $this->bearer($token))->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_profile_and_password_changes_validate_and_revoke_other_sessions(): void
    {
        $token = $this->login()->json('token');
        $user = User::where('email', 'admin@example.com')->first();
        $otherToken = $user->createToken('other-session', ['staff'])->plainTextToken;
        $this->getJson('/api/v1/profile', $this->bearer($token))->assertOk()->assertJsonPath('name', 'Admin Demo');
        $this->putJson('/api/v1/profile', ['name' => 'Updated Admin', 'email' => 'NEW.ADMIN@EXAMPLE.COM'], $this->bearer($token))->assertOk()->assertJsonPath('name', 'Updated Admin')->assertJsonPath('email', 'new.admin@example.com');
        $this->putJson('/api/v1/profile', ['roles' => ['Finance']], $this->bearer($token))->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->postJson('/api/v1/change-password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'], $this->bearer($token))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/change-password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'], $this->bearer($token))->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->getJson('/api/v1/user', $this->bearer($otherToken))->assertUnauthorized();
        $this->getJson('/api/v1/user', $this->bearer($token))->assertOk();
    }

    public function test_otp_all_hashes_code_and_challenge_and_allows_only_one_use(): void
    {
        config(['sfa.login_otp' => 'all']);
        Mail::fake();
        $code = null;
        $result = $this->login()->assertOk()->assertJsonPath('otp_required', true)->assertJsonMissingPath('token')->json();
        Mail::assertSent(LoginCodeMail::class, function ($mail) use (&$code) { $code = $mail->code; return $mail->hasTo('admin@example.com'); });
        $row = LoginChallenge::first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertNotSame($result['challenge'], $row->token_hash);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/login/otp', ['challenge' => $result['challenge'], 'code' => $code])->assertOk()->assertJsonStructure(['user', 'token']);
        $this->postJson('/api/v1/login/otp', ['challenge' => $result['challenge'], 'code' => $code])->assertStatus(410);
        $this->assertDatabaseCount('login_challenges', 0);
    }

    public function test_bound_otp_applies_only_to_accounts_with_an_attached_email(): void
    {
        config(['sfa.login_otp' => 'bound']);
        Mail::fake();
        $this->login()->assertOk()->assertJsonStructure(['token']);
        User::where('email', 'admin@example.com')->update(['otp_email' => 'verification@example.com']);
        $this->login()->assertOk()->assertJsonPath('otp_required', true)->assertJsonPath('sent_to', 've**********@example.com');
        Mail::assertSent(LoginCodeMail::class, fn ($mail) => $mail->hasTo('verification@example.com'));
    }

    public function test_otp_resend_rate_limits_and_replaces_the_previous_code(): void
    {
        config(['sfa.login_otp' => 'all']);
        Mail::fake();
        $result = $this->login()->json();
        $oldHash = LoginChallenge::first()->code_hash;
        $this->postJson('/api/v1/login/otp/resend', ['challenge' => $result['challenge']])->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login/otp/resend', ['challenge' => $result['challenge']])->assertOk()->assertJsonPath('expires_in', 600);
        $this->assertNotSame($oldHash, LoginChallenge::first()->code_hash);
        Mail::assertSentCount(2);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/login/otp/resend', ['challenge' => $result['challenge']])->assertStatus(410);
    }

    public function test_wrong_codes_count_as_failures_and_expired_codes_cannot_sign_in(): void
    {
        config(['sfa.login_otp' => 'all']);
        Mail::fake();
        $result = $this->login()->json();
        $challenge = LoginChallenge::first();
        $challenge->update(['code_hash' => Hash::make('123456')]);
        $this->postJson('/api/v1/login/otp', ['challenge' => $result['challenge'], 'code' => '000000'])->assertUnprocessable()->assertJsonPath('attempts_left', 4);
        $this->assertSame(1, LoginLockout::first()->failed_attempts);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/login/otp', ['challenge' => $result['challenge'], 'code' => '123456'])->assertStatus(410);
        $this->postJson('/api/v1/login/otp', ['challenge' => 'invalid', 'code' => 'xx'])->assertUnprocessable()->assertJsonValidationErrors(['challenge', 'code']);
    }

    public function test_lockout_blocks_an_already_pending_otp_challenge(): void
    {
        config(['sfa.login_otp' => 'all']);
        Mail::fake();
        $result = $this->login()->json();
        LoginLockout::create(['email' => 'admin@example.com', 'failed_attempts' => 6, 'locked_until' => now()->addMinutes(5), 'last_failed_at' => now()]);
        $this->postJson('/api/v1/login/otp', ['challenge' => $result['challenge'], 'code' => '123456'])->assertStatus(429);
        $this->postJson('/api/v1/login/otp/resend', ['challenge' => $result['challenge']])->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_staff_token_cannot_enter_integration_routes(): void
    {
        $token = $this->login()->json('token');
        $this->getJson('/api/v1/integrations/webhook-endpoints', $this->bearer($token))->assertForbidden();
    }
}
