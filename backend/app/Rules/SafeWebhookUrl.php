<?php

namespace App\Rules;

use App\Services\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid webhook URL.');

            return;
        }
        try {
            app(WebhookUrlGuard::class)->transportOptions($value);
        } catch (ValidationException $exception) {
            $fail($exception->errors()['url'][0]);
        }
    }
}
