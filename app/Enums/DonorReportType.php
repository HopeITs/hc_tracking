<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a report owed to a donor covers.
 *
 * The value is what lands in `donor_reports.type` (VARCHAR(50)). Whether a
 * report is interim or final is part of its title, not its type.
 */
enum DonorReportType: string
{
    use HasTranslatedLabel;

    case Narrative = 'narrative';
    case Financial = 'financial';

    /**
     * All donor report type values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
