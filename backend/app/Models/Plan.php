<?php
namespace App\Models;
class Plan extends DomainModel
{
    protected function casts(): array { return ['monthly_price_amount'=>'integer','annual_price_amount'=>'integer','trial_days'=>'integer','features'=>'array','limits'=>'array','commission_rate_bp'=>'integer','is_active'=>'boolean']; }
    public function subscriptions() { return $this->hasMany(Subscription::class); }
}
