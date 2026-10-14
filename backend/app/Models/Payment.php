<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model {
    public const STATUSES = ['pending','authorized','captured','partially_refunded','refunded','failed','cancelled','expired','disputed','dispute_won','dispute_lost'];
    protected $guarded = [];
    protected $appends = ['amount'];
    protected function casts(): array { return ['total_amount'=>'integer','captured_amount'=>'integer','refunded_amount'=>'integer','fee_amount'=>'integer','authorized_at'=>'datetime','captured_at'=>'datetime','failed_at'=>'datetime','expires_at'=>'datetime']; }
    public function getAmountAttribute(): int { return (int)$this->total_amount; }
    public function order() { return $this->belongsTo(Order::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function refunds() { return $this->hasMany(Refund::class); }
    public function ledgerEntries() { return $this->hasMany(LedgerEntry::class); }
}
