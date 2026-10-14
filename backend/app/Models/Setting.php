<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'json'];

    // Without an explicit default, the value falls back to config('sfa.settings.<key>').
    public static function valueFor(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->first()?->value ?? $default ?? config("sfa.settings.$key");
    }
}
