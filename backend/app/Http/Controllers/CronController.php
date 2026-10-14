<?php

namespace App\Http\Controllers;

use App\Services\Scheduler;
use Illuminate\Http\Request;

class CronController extends Controller
{
    public function run(Request $request, Scheduler $scheduler)
    {
        $secret = config('integrations.cron_secret');
        $provided = $request->bearerToken();
        abort_unless(is_string($secret) && $secret !== '' && is_string($provided)
            && hash_equals($secret, $provided), 401, 'Unauthenticated.');

        return response()->json($scheduler->run());
    }
}
