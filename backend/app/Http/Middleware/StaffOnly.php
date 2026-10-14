<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

class StaffOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->currentAccessToken() && ! ($user->currentAccessToken() instanceof TransientToken) && $user->tokenCan('staff'), 403, 'This action requires a staff session token.');
        if (! $user->is_active) {
            $user->tokens()->delete();
            abort(401, 'This account is inactive.');
        }

        return $next($request);
    }
}
