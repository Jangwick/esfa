<?php
namespace App\Models;
class Warehouse extends DomainModel { protected function casts(): array { return ['is_active' => 'boolean']; } public function address() { return $this->belongsTo(Address::class); } public function seller() { return $this->belongsTo(Seller::class); } }
