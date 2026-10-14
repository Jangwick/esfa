<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['response_headers' => 'array', 'created_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
