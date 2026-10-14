<?php

namespace App\Services\Leave;

use InvalidArgumentException;

final class LeaveAmount
{
    public static function toCents(string $amount): int
    {
        if (! preg_match('/^(-?)(\d{1,8})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Leave amounts must be decimal strings with at most two fractional digits.');
        }

        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $cents = ((int) $matches[2] * 100) + (int) $fraction;

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        if ($cents === 0) {
            return '0.00';
        }

        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
