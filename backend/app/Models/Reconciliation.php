<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Reconciliation extends Model {
    protected $guarded = [];
    protected $hidden = ['content'];
    protected function casts(): array { return ['date_from'=>'date','date_to'=>'date']; }
    public function items() { return $this->hasMany(ReconciliationItem::class); }
}
