<?php
namespace App\Models;
class CommissionRule extends DomainModel
{
    protected function casts(): array { return ['effective_from'=>'immutable_datetime','effective_to'=>'immutable_datetime','rate_bp'=>'integer','fixed_fee_amount'=>'integer']; }
}
