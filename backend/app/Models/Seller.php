<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Seller extends Model {
    protected $guarded = [];
    protected $hidden = ['payout_destination'];
    protected $appends = ['payout_destination_masked','name'];
    public function getNameAttribute(): string { return $this->owner_name ?: $this->shop_name; }
    protected function casts(): array { return ['pickup_address'=>'array','payout_destination'=>'encrypted:array']; }
    public function getPayoutDestinationMaskedAttribute(): ?string {
        $destination = $this->payout_destination;
        if (!$destination) { return null; }
        $number = $destination['account_number'] ?? $destination['number'] ?? '';
        return '•••• '.substr((string)$number,-4);
    }
    public function orderItems() { return $this->hasMany(OrderItem::class); }
}
