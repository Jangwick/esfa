<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginChallenge extends Model
{
    public const MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_AFTER = 60;
    protected $fillable = ['user_id', 'token_hash', 'code_hash', 'attempts', 'expires_at', 'last_sent_at'];
    protected $hidden = ['token_hash', 'code_hash'];
    protected $casts = ['attempts' => 'integer', 'expires_at' => 'datetime', 'last_sent_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
