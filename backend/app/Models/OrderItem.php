<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderItem extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['quantity'=>'integer','unit_price_amount'=>'integer','line_total_amount'=>'integer']; }
    public function order() { return $this->belongsTo(Order::class); }
    public function seller() { return $this->belongsTo(Seller::class); }
    public function category() { return $this->belongsTo(Category::class); }
}
