<?php
namespace App\Models;
class Payout extends DomainModel
{
    protected function casts(): array { return ['amount'=>'integer','paid_at'=>'immutable_datetime']; }
    public function settlement() { return $this->belongsTo(Settlement::class); }
}
