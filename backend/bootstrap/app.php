<?php

use App\Http\Middleware\AuditWrites;
use App\Http\Middleware\CacheApiResponse;
use App\Http\Middleware\FlushApiCache;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\IntegrationOnly;
use App\Http\Middleware\RunScheduler;
use App\Http\Middleware\StaffOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Spatie\Permission\Exceptions\UnauthorizedException;

if (PHP_SAPI === 'apache2handler') {
    Env::disablePutenv();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', apiPrefix: 'api/v1')
    ->withMiddleware(function (Middleware $middleware): void {
        // Vercel terminates HTTPS and sets the client headers; start.sh forwards to Caddy on loopback.
        if (env('VERCEL') === '1') {
            $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers:
                Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PROTO
            );
        }
        $middleware->alias([
            'staff' => StaffOnly::class,
            'audit.log' => AuditWrites::class,
            'cache.api' => CacheApiResponse::class,
            'cache.flush' => FlushApiCache::class,
            'integration.only' => IntegrationOnly::class,
            'idempotency' => Idempotency::class,
            'run.scheduler' => RunScheduler::class,
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (UnauthorizedException $exception) {
            return response()->json(['message' => 'This action requires the appropriate permission.'], 403);
        });
    })->create();
