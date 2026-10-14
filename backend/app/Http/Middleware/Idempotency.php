<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyRecord;
use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof IntegrationClient, 403, 'This action requires an integration client token.');
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 255) {
            throw ValidationException::withMessages(['idempotency_key' => ['An Idempotency-Key header of 1–255 characters is required.']]);
        }
        // Method, path and query prevent a shared key from replaying a different operation.
        $hash = hash('sha256', $request->method()."\n".$request->getRequestUri()."\n".$request->getContent());
        $clientId = $request->user()->getKey();
        DB::beginTransaction();
        try {
            IdempotencyRecord::where('client_id', $clientId)->where('key', $key)->where('expires_at', '<=', now())->delete();
            $created = DB::table('idempotency_records')->insertOrIgnore([
                'client_id' => $clientId, 'key' => $key, 'request_hash' => $hash,
                'created_at' => now(), 'expires_at' => now()->addDay(),
            ]);
            $record = IdempotencyRecord::where('client_id', $clientId)->where('key', $key)->lockForUpdate()->firstOrFail();
            if (! hash_equals($record->request_hash, $hash)) {
                throw ValidationException::withMessages(['idempotency_key' => ['This key has already been used for a different request.']]);
            }
            if (! $created) {
                if ($record->response_status === null) {
                    DB::commit();

                    return response()->json(['message' => 'A request with this key is still processing.'], 409, ['Retry-After' => '5']);
                }
                $response = response($record->response_body, $record->response_status, $record->response_headers ?? []);
                $response->headers->set('Idempotency-Replayed', 'true');
                DB::commit();

                return $response;
            }
            $response = $next($request);
            if ($response->getStatusCode() >= 500) {
                DB::rollBack();

                return $response;
            }
            $record->update([
                'response_status' => $response->getStatusCode(), 'response_body' => $response->getContent(),
                'response_headers' => ['Content-Type' => $response->headers->get('Content-Type', 'application/json')],
            ]);
            DB::commit();

            return $response;
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }
}
