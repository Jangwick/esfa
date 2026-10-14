<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginLockout extends Model
{
    public const FREE_ATTEMPTS = 5;
    public const LOCK_MINUTES = [6 => 5, 7 => 10];
    public const LONGEST_LOCK_MINUTES = 1440;
    public const FORGET_AFTER_HOURS = 24;

    protected $fillable = ['email', 'failed_attempts', 'locked_until', 'last_failed_at'];
    protected $casts = ['failed_attempts' => 'integer', 'locked_until' => 'datetime', 'last_failed_at' => 'datetime'];

    public static function secondsLeft(string $email): int
    {
        $row = static::where('email', static::key($email))->first();

        return $row?->locked_until?->isFuture() ? max(1, (int) ceil(now()->diffInSeconds($row->locked_until))) : 0;
    }

    public static function recordFailure(string $email): static
    {
        return DB::transaction(function () use ($email) {
            $key = static::key($email);
            static::query()->insertOrIgnore(['email' => $key, 'failed_attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = static::where('email', $key)->lockForUpdate()->firstOrFail();
            if ($row->locked_until?->isFuture()) {
                return $row;
            }
            if ($row->last_failed_at?->lte(now()->subHours(static::FORGET_AFTER_HOURS))) {
                $row->failed_attempts = 0;
            }
            $row->failed_attempts++;
            $minutes = $row->failed_attempts <= static::FREE_ATTEMPTS ? 0 : (static::LOCK_MINUTES[$row->failed_attempts] ?? static::LONGEST_LOCK_MINUTES);
            $row->locked_until = $minutes ? now()->addMinutes($minutes) : null;
            $row->last_failed_at = now();
            $row->save();

            return $row;
        });
    }

    public static function clear(string $email): void
    {
        static::where('email', static::key($email))->delete();
    }

    private static function key(string $email): string
    {
        return Str::lower(trim($email));
    }
}
