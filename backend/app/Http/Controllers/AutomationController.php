<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;
use App\Models\SchedulerLock;
use App\Models\Setting;
use App\Services\Scheduler;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AutomationController extends Controller
{
    public function index()
    {
        $this->requirePermission('manage settings');
        $lock = SchedulerLock::find('scheduled-work');

        return response()->json([
            'enabled' => (bool) config('sfa.scheduler_enabled'),
            'running' => (bool) $lock?->locked_until?->isFuture(),
            'last_run_at' => $lock?->last_run_at?->toIso8601String(),
            'last_result' => $lock?->last_result,
            'ai' => $this->aiUsage(),
        ]);
    }

    // Whether Gemini is set up (never the key itself) and today's use, for the AI assistant section.
    private function aiUsage(): array
    {
        $today = AiInsight::where('created_at', '>=', CarbonImmutable::now(Setting::valueFor('business_timezone', 'Asia/Manila'))->startOfDay()->utc())->selectRaw('count(*) as calls, coalesce(sum(prompt_tokens), 0) as prompt, coalesce(sum(output_tokens), 0) as output')->first();
        $usd = $today->prompt / 1e6 * config('services.gemini.usd_per_million_input') + $today->output / 1e6 * config('services.gemini.usd_per_million_output');

        return [
            'configured' => (bool) config('services.gemini.key'),
            'model' => config('services.gemini.model'),
            'fallback_model' => config('services.gemini.fallback_model') ?: null,
            'today' => ['calls' => (int) $today->calls, 'tokens' => (int) $today->prompt + (int) $today->output, 'estimated_usd' => round($usd, 4)],
        ];
    }

    // "Run due work now": the same idempotent jobs as the cron, under the same lock (ran: false while another run holds it).
    public function run(Scheduler $scheduler)
    {
        $this->requirePermission('manage settings');
        if (! config('sfa.scheduler_enabled')) {
            throw ValidationException::withMessages(['scheduler' => 'Scheduled work is paused (SFA_SCHEDULER_ENABLED=false).']);
        }

        return response()->json($scheduler->run());
    }
}
