<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke()
    {
        try { DB::select('SELECT 1'); $database = 'connected'; }
        catch (Throwable) { $database = 'error'; }

        return response()->json(['status' => $database === 'connected' ? 'ok' : 'degraded', 'commit' => config('sfa.commit'), 'database' => $database], $database === 'connected' ? 200 : 503);
    }
}
