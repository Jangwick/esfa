<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Admin authorization follows the role, including permissions added after seeding.
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole('Admin') ? true : null);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('integrations', fn (Request $request) => Limit::perMinute(120)->by('integration:'.($request->user()?->getKey() ?? $request->ip())));
        // 300 inbound webhooks per minute for each payment gateway and each courier.
        RateLimiter::for('inbound-webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhook:'.($request->route('gateway') !== null ? 'gateway:'.$request->route('gateway') : 'courier:'.($request->route('courierCode') ?? $request->ip()))));
        // A FormRequest is a copy of the HTTP request with its own attribute bag. Share the real bag so
        // audit metadata set in controllers (audit.entity_type, audit.old_values, ...) reaches AuditWrites.
        $this->app->resolving(FormRequest::class, function (FormRequest $request, $app): void {
            $request->attributes = $app['request']->attributes;
        });
    }
}
