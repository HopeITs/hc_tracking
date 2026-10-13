<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who collects an Indicator Library entry (`library_indicators.collected_by`).
 *
 * The default for a report template line's `entered_by`, and the default
 * denominator basis of a percentage entry.
 *
 * Values are lower case and casing is part of the value.
 */
enum IndicatorCollector: string
{
    use HasTranslatedLabel;

    case ProjectTeam = 'project_team';
    case Meal = 'meal';

    /**
     * All collector values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The denominator a percentage collected this way uses unless MEAL
     * overrides it: a project team counts against the fixed target
     * beneficiaries, MEAL enters numerator and denominator (D-25).
     */
    public function defaultDenominatorBasis(): DenominatorBasis
    {
        return match ($this) {
            self::ProjectTeam => DenominatorBasis::Fixed,
            self::Meal => DenominatorBasis::Entered,
        };
    }
}
