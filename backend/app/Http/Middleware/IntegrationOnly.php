<?php

namespace App\Http\Middleware;

use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class IntegrationOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();
        abort_unless($client instanceof IntegrationClient && $client->currentAccessToken() instanceof PersonalAccessToken,
            403, 'This action requires an integration client token.');
        abort_unless($client->is_active, 403, 'This integration client is inactive.');

        return $next($request);
    }
}
