<?php

namespace App\Http\Middleware;

use App\Services\Scheduler;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RunScheduler
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Throttled to one run per five minutes by Scheduler::run(true), which also honours SFA_SCHEDULER_ENABLED=false.
        if (! $request->user()) {
            return;
        }
        try {
            app(Scheduler::class)->run(true);
        } catch (Throwable $exception) {
            Log::error('Scheduled SFA work failed.', ['exception' => $exception::class]);
        }
    }
}
