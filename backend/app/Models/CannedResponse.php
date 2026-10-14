<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CannedResponse extends Model
{
    protected $guarded = [];
    protected $casts = ['is_shared' => 'boolean'];
    public function user() { return $this->belongsTo(User::class); }
}
