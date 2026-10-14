<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlaPolicy extends Model
{
    protected $guarded = [];
    protected $casts = ['first_response_hours' => 'integer', 'resolution_hours' => 'integer'];

    public static function hoursFor(string $priority): array
    {
        $policy = static::where('priority', $priority)->first();

        return $policy ? ['first_response_hours' => $policy->first_response_hours, 'resolution_hours' => $policy->resolution_hours] : (Setting::valueFor('sla_policies', config('sfa.settings.sla_policies'))[$priority]);
    }
}
