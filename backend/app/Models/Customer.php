<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Customer extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['default_address' => 'array']; }
    public function orders() { return $this->hasMany(Order::class); }
    public function payments() { return $this->hasMany(Payment::class); }
}
