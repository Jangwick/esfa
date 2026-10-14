<?php
namespace App\Models;
class CommissionEntry extends DomainModel
{
    protected function casts(): array { return ['gross_amount'=>'integer','commission_amount'=>'integer','seller_net_amount'=>'integer','fixed_fee_amount'=>'integer','rate_bp'=>'integer']; }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function refund() { return $this->belongsTo(Refund::class); }
    public function settlement() { return $this->belongsTo(Settlement::class); }
}
