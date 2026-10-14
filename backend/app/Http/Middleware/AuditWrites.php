<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditWrites
{
    public function handle(Request $request, Closure $next)
    {
        $model = null;
        $old = null;
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            foreach ($request->route()?->parameters() ?? [] as $value) {
                if ($value instanceof Model) {
                    $model = $value;
                    $old = $value->toArray();
                    break;
                }
            }
        }
        $response = $next($request);
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $response->isSuccessful() && ! $request->attributes->get('audit.skip')) {
            $body = method_exists($response, 'getData') ? $response->getData(true) : [];
            $new = $model?->fresh()?->toArray() ?? ($body ?: $request->except(['password', 'password_confirmation', 'current_password']));
            $entity = $request->attributes->get('audit.entity_type', $model ? class_basename($model) : Str::singular($request->segment(3) ?: 'system'));
            $entityId = $request->attributes->get('audit.entity_id', $model?->getKey() ?? ($body['id'] ?? null));
            AuditLog::create([
                'user_id' => $request->user() instanceof User ? $request->user()->id : null,
                // A POST that acts on an existing record (status change, approval, payout) is an update, not a creation.
                'action' => $request->attributes->get('audit.action', match ($request->method()) { 'POST' => $model || $request->attributes->get('audit.old_values') !== null ? 'updated' : 'created', 'DELETE' => 'deleted', default => 'updated' }),
                'entity_type' => $entity,
                'entity_id' => is_scalar($entityId) ? (string) $entityId : null,
                'old_values' => self::sanitize($request->attributes->get('audit.old_values', $old)),
                'new_values' => $request->isMethod('DELETE') ? null : self::sanitize($request->attributes->get('audit.new_values', $new)),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return $response;
    }

    public static function sanitize(?array $values): ?array
    {
        if ($values === null) { return null; }
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|code_hash|authorization|content|card_number|cvv|credentials/i', (string) $key)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = self::sanitize($value);
            }
        }

        return $values;
    }
}
