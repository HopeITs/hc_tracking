<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How often progress is checked against a target.
 *
 * Shared by `results.checkout_period` and `plan_indicators.period`, both
 * VARCHAR(50). The two columns describe the same idea and previously used
 * different words for it — `results.checkout_period` defaulted to 'quarter'
 * while `plan_indicators.period` defaulted to 'quarterly'. One enum now spells
 * it one way, so a report grouping on either column sees the same value.
 *
 * Values are lower case and casing is part of the value.
 */
enum Period: string
{
    use HasTranslatedLabel;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi-annual';
    case Annual = 'annual';

    /**
     * All period values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
