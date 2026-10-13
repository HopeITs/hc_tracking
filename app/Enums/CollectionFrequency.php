<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How often an Indicator Library entry is collected (`library_indicators.frequency`).
 *
 * The default schedule for a report template line. Only the four calendar
 * frequencies map to a target Period; the rest (per event, per season,
 * follow-up, baseline and endline, one time) have no fixed cadence.
 *
 * Values are lower case and casing is part of the value.
 */
enum CollectionFrequency: string
{
    use HasTranslatedLabel;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi-annual';
    case Annual = 'annual';
    case PerEvent = 'per-event';
    case PerSeason = 'per-season';
    case FollowUp = 'follow-up';
    case BaselineEndline = 'baseline-endline';
    case OneTime = 'one-time';

    /**
     * All frequency values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The target period this frequency schedules, or null when it has no
     * calendar cadence.
     */
    public function period(): ?Period
    {
        return match ($this) {
            self::Monthly => Period::Monthly,
            self::Quarterly => Period::Quarterly,
            self::SemiAnnual => Period::SemiAnnual,
            self::Annual => Period::Annual,
            self::PerEvent, self::PerSeason, self::FollowUp, self::BaselineEndline, self::OneTime => null,
        };
    }
}
