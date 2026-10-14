<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerLock extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['locked_until' => 'datetime', 'last_run_at' => 'datetime', 'last_result' => 'array'];
    }
}
