<?php
namespace App\Models;
class Rate extends DomainModel
{
    protected $table = 'shipping_rates';
    protected function casts(): array { return ['base_fee_amount' => 'integer', 'additional_per_500g_amount' => 'integer', 'cod_fee_bp' => 'integer', 'is_active' => 'boolean']; }
    public function courier() { return $this->belongsTo(Courier::class); }
    public function originZone() { return $this->belongsTo(Zone::class, 'origin_zone_id'); }
    public function destinationZone() { return $this->belongsTo(Zone::class, 'destination_zone_id'); }
}
