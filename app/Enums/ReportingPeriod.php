<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The period a donor report covers (contracts §14.2).
 *
 * The value is what lands in `donor_reports.period_type`. Fixed periods run
 * from the chosen start month; custom runs between two chosen months.
 */
enum ReportingPeriod: string
{
    use HasTranslatedLabel;

    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi-annual';
    case Annual = 'annual';
    case Custom = 'custom';

    /**
     * How many calendar months a fixed period spans, or null for custom.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
            self::Custom => null,
        };
    }

    /**
     * All reporting period values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
