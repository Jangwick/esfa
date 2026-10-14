<?php
return ['gemini' => [
    'key' => env('SFA_GEMINI_API_KEY'),
    // Pinned on purpose: a "-latest" alias can move to another model and change the JSON answers SFA relies on.
    // gemini-3.5-flash-lite: stable, 1M-token input, 65k output, structured JSON, function calling and the low/medium
    // thinking levels SFA uses. On the free tier it allows 15 requests a minute and 500 a day; every Flash model allows
    // 5 and 20, which SFA used up in one morning of testing (2026-10-12).
    'model' => env('SFA_GEMINI_MODEL', 'gemini-3.5-flash-lite'),
    // Tried once when the model answers 429 (rate limit) or 5xx; empty = no retry.
    'fallback_model' => env('SFA_GEMINI_FALLBACK_MODEL', 'gemini-3.1-flash-lite'),
    'timeout' => (int) env('SFA_GEMINI_TIMEOUT', 25),
    // Pause before the last retry of a request someone is waiting for (AiService::send).
    'retry_pause_ms' => (int) env('SFA_GEMINI_RETRY_PAUSE_MS', 2000),
    // Estimated cost on the Automation tab only (USD per 1M tokens, gemini-3.5-flash-lite paid tier, 2026 prices).
    'usd_per_million_input' => (float) env('SFA_GEMINI_USD_PER_M_INPUT', 0.30),
    'usd_per_million_output' => (float) env('SFA_GEMINI_USD_PER_M_OUTPUT', 2.50),
]];
