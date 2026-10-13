<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a percentage Indicator Library entry divides by
 * (`library_indicators.denominator_basis`, percentage entries only).
 *
 * `fixed`: the count entered ÷ the project's `target_beneficiaries`.
 * `entered`: a numerator and a denominator are both entered.
 *
 * Values are lower case and casing is part of the value.
 */
enum DenominatorBasis: string
{
    use HasTranslatedLabel;

    case Fixed = 'fixed';
    case Entered = 'entered';

    /**
     * All basis values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
