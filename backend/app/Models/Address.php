<?php
namespace App\Models;
class Address extends DomainModel
{
    public function region() { return $this->belongsTo(Region::class); }
    public function province() { return $this->belongsTo(Province::class); }
    public function city() { return $this->belongsTo(City::class); }
    public function barangay() { return $this->belongsTo(Barangay::class); }
    protected function casts(): array { return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7']; }
}
