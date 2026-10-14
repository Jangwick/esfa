<?php
namespace App\Models;
class Province extends DomainModel { public function region() { return $this->belongsTo(Region::class); } public function cities() { return $this->hasMany(City::class); } }
