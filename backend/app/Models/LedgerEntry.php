<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use LogicException;
class LedgerEntry extends Model {
    public $timestamps = false;
    protected $guarded = [];
    protected $appends = ['amount'];
    protected function casts(): array { return ['total_amount'=>'integer','created_at'=>'datetime']; }
    public function getAmountAttribute(): int { return (int)$this->total_amount; }
    protected static function booted(): void {
        static::updating(fn()=>throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn()=>throw new LogicException('Ledger entries are append-only.'));
    }
}
