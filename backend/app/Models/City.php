<?php
namespace App\Models;
class City extends DomainModel { protected $table = 'cities'; public function region() { return $this->belongsTo(Region::class); } public function province() { return $this->belongsTo(Province::class); } public function barangays() { return $this->hasMany(Barangay::class); } }
