<?php

namespace App\Models;

use App\Http\Middleware\AuditWrites;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address', 'user_agent'];
    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // An automatic change: no user, and new_values.automation names the rule that made it.
    public static function system(Model $entity, ?array $old, ?array $new, string $rule): self
    {
        return static::create([
            'action' => 'updated', 'entity_type' => class_basename($entity), 'entity_id' => (string) $entity->getKey(),
            'old_values' => AuditWrites::sanitize($old), 'new_values' => AuditWrites::sanitize([...($new ?? []), 'automation' => $rule]),
        ]);
    }
}
