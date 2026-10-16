<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * When a narrative question is asked (`narrative_questions.frequency`,
 * contracts.md §12.4): in every monthly report, or only in the reports of
 * calendar quarter-end months (Mar, Jun, Sep, Dec).
 *
 * Values are lower case and casing is part of the value.
 */
enum NarrativeQuestionFrequency: string
{
    use HasTranslatedLabel;

    case Monthly = 'monthly';
    case QuarterEnd = 'quarter_end';

    /**
     * All frequency values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
