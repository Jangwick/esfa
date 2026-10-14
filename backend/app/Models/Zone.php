<?php
namespace App\Models;
class Zone extends DomainModel
{
    protected function casts(): array { return ['province_ids' => 'array', 'city_ids' => 'array', 'min_days' => 'integer', 'max_days' => 'integer', 'is_active' => 'boolean']; }
    public function includesCity(City $city): bool { return in_array($city->id, $this->city_ids, true) || ($city->province_id && in_array($city->province_id, $this->province_ids, true)); }
}
