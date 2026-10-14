<?php

use App\Services\Scheduler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('sfa:run-scheduled', function (Scheduler $scheduler) {
    $result = $scheduler->run();
    if (! $result['ran']) {
        $this->info(isset($result['paused']) ? 'Scheduled work is paused (SFA_SCHEDULER_ENABLED=false).' : 'Scheduled work is already running.');

        return 0;
    }
    $width = max(array_map('strlen', array_keys($result))) + 2;
    foreach ($result as $key => $value) {
        $this->line(str_pad($key, $width).(is_array($value) ? (implode(', ', $value) ?: 'none') : $value));
    }

    return $result['failed_jobs'] ? 1 : 0;
})->purpose('Run due SFA scheduled work');

// Setup check for the AI assistant: one tiny structured request with the configured key and model, ignoring the
// Settings switches. Prints the model and latency; the key is never printed.
Artisan::command('sfa:ai-check {--fixture= : Save the raw Gemini response to this file (for test fakes)}', function (\App\Services\AiService $ai) {
    if (! config('services.gemini.key')) { $this->error('SFA_GEMINI_API_KEY is empty.'); return 1; }
    $started = microtime(true);
    try {
        [$model, $response] = $ai->send([
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with ok=true and the word "ready".']]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseJsonSchema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'word' => ['type' => 'string']], 'required' => ['ok', 'word']], 'thinkingConfig' => ['thinkingLevel' => 'low']],
        ], 30);
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
    if ($path = $this->option('fixture')) { file_put_contents($path, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"); }
    $this->info(sprintf('OK: %s answered %s in %d ms (%s).', $model, \App\Services\AiService::text($response), (microtime(true) - $started) * 1000, json_encode(\App\Services\AiService::usage($response))));

    return 0;
})->purpose('Check that the Gemini key and model work');

Schedule::command('sfa:run-scheduled')->everyMinute();
