<?php
namespace App\Models;
class Subscription extends DomainModel
{
    protected function casts(): array { return ['trial_ends_at'=>'immutable_datetime','current_period_start'=>'immutable_datetime','current_period_end'=>'immutable_datetime','cancelled_at'=>'immutable_datetime','next_retry_at'=>'immutable_datetime','cancel_at_period_end'=>'boolean']; }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function nextPlan() { return $this->belongsTo(Plan::class,'next_plan_id'); }
    public function invoices() { return $this->hasMany(Invoice::class); }
}
