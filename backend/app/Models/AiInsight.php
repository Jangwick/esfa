<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class AiInsight extends Model
{
    protected $fillable = ['feature', 'subject_type', 'subject_id', 'status', 'model', 'input_hash', 'output', 'error', 'prompt_tokens', 'output_tokens', 'created_by'];
    protected $casts = ['output' => 'array'];
    protected $hidden = ['input_hash'];

    public function ok(): bool
    {
        return $this->status === 'ok';
    }

    // The answer arrived but SFA's own checks rejected it (a value outside SFA's lists, a missing field).
    public function reject(string $error): self
    {
        $this->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500)]);

        return $this;
    }

    // Tokens counted since business midnight against ai_daily_token_limit (failed answers cost tokens too).
    public static function tokensToday(): int
    {
        return (int) static::where('created_at', '>=', CarbonImmutable::now(Setting::valueFor('business_timezone', 'Asia/Manila'))->startOfDay()->utc())->sum(\DB::raw('prompt_tokens + output_tokens'));
    }
}
