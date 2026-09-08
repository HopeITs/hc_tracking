<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an indicator's value is expressed.
 *
 * The value is what lands in `indicators.measure` (VARCHAR(50)), which
 * defaults to 'numeric'. A numeric indicator counts something — households
 * reached, wells repaired. A percentage indicator states a proportion of a
 * whole, so its targets and results are read against 100 rather than a count.
 *
 * Values are lower case and casing is part of the value.
 */
enum IndicatorMeasure: string
{
    case Numeric = 'numeric';
    case Percentage = 'percentage';

    /**
     * All measure values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
