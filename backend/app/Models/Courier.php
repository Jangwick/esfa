<?php
namespace App\Models;
class Courier extends DomainModel
{
    public const SERVICES = ['standard', 'express', 'same_day'];
    protected $hidden = ['webhook_secret'];
    protected function casts(): array { return ['service_types' => 'array', 'webhook_secret' => 'encrypted', 'is_active' => 'boolean', 'volumetric_divisor' => 'integer']; }
    public function rates() { return $this->hasMany(Rate::class); }
}
