<?php
namespace App\Models;
class Region extends DomainModel { public function provinces() { return $this->hasMany(Province::class); } public function cities() { return $this->hasMany(City::class); } }
