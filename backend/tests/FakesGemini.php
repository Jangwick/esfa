<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

// Tests never reach Gemini: answers are queued fakes shaped like the recorded tests/Fixtures/gemini/structured.json.
trait FakesGemini
{
    protected function enableAi(string ...$features): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash-lite', 'services.gemini.fallback_model' => 'gemini-3.1-flash-lite', 'services.gemini.retry_pause_ms' => 0]);
        foreach (['ai_enabled', ...array_map(fn ($feature) => 'ai_'.$feature, $features)] as $key) { Setting::updateOrCreate(['key' => $key], ['value' => true]); }
    }

    /**
     * Queues Gemini's answers in order: an array is the JSON answer (or a whole raw response when it has "candidates"),
     * an int is an HTTP error status. An unexpected extra request fails the test.
     */
    protected function fakeGemini(array|int ...$answers): void
    {
        // A fresh client each time: Laravel invokes every earlier stub too, and a used-up sequence would throw.
        Http::swap(new Factory);
        $sequence = Http::sequence();
        foreach ($answers as $answer) {
            if (is_int($answer)) { $sequence->push(['error' => ['code' => $answer, 'message' => 'Fake Gemini error.']], $answer); } else { $sequence->push(isset($answer['candidates']) ? $answer : self::geminiAnswer($answer)); }
        }
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);
    }

    protected function fakeGeminiTimeout(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Operation timed out.'));
    }

    protected static function geminiAnswer(array $json, string $finish = 'STOP'): array
    {
        $response = json_decode(file_get_contents(__DIR__.'/Fixtures/gemini/structured.json'), true);
        $response['candidates'][0]['content']['parts'][0]['text'] = json_encode($json);
        $response['candidates'][0]['finishReason'] = $finish;
        $response['usageMetadata'] = ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 30, 'totalTokenCount' => 150];

        return $response;
    }
}
