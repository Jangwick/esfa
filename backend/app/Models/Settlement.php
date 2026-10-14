<?php
namespace App\Models;
class Settlement extends DomainModel
{
    protected function casts(): array { return ['gross_sales_amount'=>'integer','refunds_amount'=>'integer','commission_amount'=>'integer','gateway_fees_amount'=>'integer','subscription_fees_amount'=>'integer','adjustments_amount'=>'integer','net_payable_amount'=>'integer','period_start'=>'date:Y-m-d','period_end'=>'date:Y-m-d','finalized_at'=>'immutable_datetime','paid_at'=>'immutable_datetime']; }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function entries() { return $this->hasMany(CommissionEntry::class); }
    public function payouts() { return $this->hasMany(Payout::class); }
}
