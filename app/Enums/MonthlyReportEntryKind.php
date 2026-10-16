<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\LibraryIndicator;

/**
 * How a monthly report line is entered (contracts §13 Entry, D-25). Internal:
 * never stored and never shown, so it has no label.
 *
 * - Count: the manager enters a count (0 allowed); value = the count.
 * - FixedRatio: a percentage with `denominator_basis = fixed`; the manager
 *   enters the count only, the denominator is the fixed denominator.
 * - EnteredRatio: a percentage or average measured with numerator and
 *   denominator, or marked Not measured.
 */
enum MonthlyReportEntryKind: string
{
    case Count = 'count';
    case FixedRatio = 'fixed_ratio';
    case EnteredRatio = 'entered_ratio';

    public static function for(LibraryIndicator $indicator): self
    {
        return match ($indicator->measure) {
            IndicatorMeasure::Numeric => self::Count,
            IndicatorMeasure::Percentage => $indicator->denominator_basis === DenominatorBasis::Fixed ? self::FixedRatio : self::EnteredRatio,
            IndicatorMeasure::Average => self::EnteredRatio,
        };
    }

    /**
     * Whether a percentage is computed ×100.
     */
    public static function multiplierFor(LibraryIndicator $indicator): int
    {
        return $indicator->measure === IndicatorMeasure::Percentage ? 100 : 1;
    }

    /**
     * Whether the figures are complete enough to submit.
     */
    public function isFilled(mixed $value, mixed $numerator, bool $notMeasured): bool
    {
        return match ($this) {
            self::Count => $value !== null,
            self::FixedRatio => $numerator !== null,
            self::EnteredRatio => $notMeasured || $value !== null,
        };
    }
}
