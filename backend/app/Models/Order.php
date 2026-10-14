<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Order extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['placed_at'=>'datetime','subtotal_amount'=>'integer','shipping_amount'=>'integer','discount_amount'=>'integer','total_amount'=>'integer']; }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function items() { return $this->hasMany(OrderItem::class); }
    public function orderItems() { return $this->hasMany(OrderItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function shipments() { return $this->hasMany(Shipment::class); }
}
