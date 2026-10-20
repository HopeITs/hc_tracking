<?php

declare(strict_types=1);

namespace App\Support\Desk;

use BackedEnum;

/**
 * An amount the way the Desk writes it: the currency symbol ($, €) or
 * code, then the rounded whole amount with thousands separators.
 */
final class Money
{
    private const array Symbols = ['USD' => '$', 'EUR' => '€'];

    public static function format(float|int|string|null $amount, BackedEnum|string $currency): string
    {
        $code = $currency instanceof BackedEnum ? (string) $currency->value : $currency;

        return (self::Symbols[$code] ?? $code.' ').number_format((float) $amount);
    }
}
