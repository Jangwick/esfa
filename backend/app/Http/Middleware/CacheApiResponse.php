<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CacheApiResponse
{
    public function handle(Request $request, Closure $next, string $seconds = '30')
    {
        if (! $request->isMethod('GET') || $request->query('export') === 'csv') {
            return $next($request);
        }
        $version = Cache::get('sfa.api.version', 'initial');
        $identity = get_class($request->user()).':'.$request->user()->getKey();
        if ($request->user() instanceof \App\Models\User) {
            $identity .= '|'.$request->user()->getRoleNames()->sort()->implode(',').'|'.$request->user()->effectivePermissions()->pluck('name')->sort()->implode(',');
        }
        $key = 'sfa.api:'.hash('sha256', $version.'|'.$identity.'|'.$request->fullUrl());
        $cached = Cache::get($key);
        if ($cached !== null) {
            return response($cached['content'], $cached['status'])->withHeaders($cached['headers']);
        }
        $response = $next($request);
        if ($response->isSuccessful() && str_contains($response->headers->get('Content-Type', ''), 'application/json')) {
            Cache::put($key, ['content' => $response->getContent(), 'status' => $response->getStatusCode(), 'headers' => ['Content-Type' => 'application/json']], max(1, min(3600, (int) $seconds)));
        }

        return $response;
    }
}
