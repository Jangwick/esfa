<?php
namespace App\Models;
class Barangay extends DomainModel { public function city() { return $this->belongsTo(City::class); } }
