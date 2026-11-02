<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a monthly report stands (contracts §13, D-07).
 *
 * The center manager fills a draft and submits it; the responsible MEAL
 * officer validates it or returns it to draft. Only validated figures count
 * towards achievement, through the `reports` mirrors (mirrorStatus()).
 */
enum MonthlyReportStatus: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Validated = 'validated';

    /**
     * The status every `reports` mirror of a report in this state carries.
     */
    public function mirrorStatus(): ReportStatus
    {
        return match ($this) {
            self::Draft => ReportStatus::Draft,
            self::Submitted => ReportStatus::Reviewed,
            self::Validated => ReportStatus::Approved,
        };
    }

    /**
     * Whether the manager may still change the report.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft => true,
            self::Submitted, self::Validated => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The shared visual tone for this case (W-28); exhaustive, no default arm.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Neutral,
            self::Submitted => StatusTone::Active,
            self::Validated => StatusTone::Good,
        };
    }
}
