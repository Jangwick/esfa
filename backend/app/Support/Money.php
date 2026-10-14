<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function halfUp(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $amount < 0 || $numerator < 0) {
            throw ValidationException::withMessages(['amount' => ['Money ratios must have nonnegative amounts and a positive divisor.']]);
        }
        $product = bcmul((string) $amount, (string) $numerator, 0);
        $result = bcdiv(bcadd(bcmul($product, '2', 0), (string) $denominator, 0), bcmul((string) $denominator, '2', 0), 0);
        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0) {
            throw ValidationException::withMessages(['amount' => ['The calculated amount exceeds the supported integer range.']]);
        }
        return (int) $result;
    }

    public static function pesos(string $value): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($value), $parts)) {
            throw ValidationException::withMessages(['amount' => ['Enter pesos with no more than two decimal places.']]);
        }
        $result = bcadd(bcmul($parts[1], '100', 0), str_pad($parts[2] ?? '', 2, '0'), 0);
        if (bccomp($result, '9000000000000000', 0) > 0) {
            throw ValidationException::withMessages(['amount' => ['The amount is too large.']]);
        }
        return (int) $result;
    }
}
