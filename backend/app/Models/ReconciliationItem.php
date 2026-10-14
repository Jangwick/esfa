<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReconciliationItem extends Model {
    protected $guarded = [];
    protected $appends = ['amount'];
    protected function casts(): array { return ['total_amount'=>'integer','fee_amount'=>'integer','date'=>'date','resolved_at'=>'datetime']; }
    public function getAmountAttribute(): int { return (int)$this->total_amount; }
    public function payment() { return $this->belongsTo(Payment::class); }
}
