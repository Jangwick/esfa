<?php
namespace App\Models;
class ShipmentEvent extends DomainModel { protected function casts(): array { return ['occurred_at' => 'datetime']; } public function shipment() { return $this->belongsTo(Shipment::class); } }
