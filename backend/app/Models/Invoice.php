<?php
namespace App\Models;
class Invoice extends DomainModel
{
    protected function casts(): array { return ['period_start'=>'immutable_datetime','period_end'=>'immutable_datetime','line_items'=>'array','subtotal_amount'=>'integer','total_amount'=>'integer','paid_at'=>'immutable_datetime','due_date'=>'date:Y-m-d']; }
    public function subscription() { return $this->belongsTo(Subscription::class); }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function payment() { return $this->belongsTo(Payment::class); }
}
