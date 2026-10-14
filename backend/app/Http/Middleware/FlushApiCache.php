<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlushApiCache
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->isSuccessful()) {
            Cache::forever('sfa.api.version', (string) Str::uuid());
        }

        return $response;
    }
}
