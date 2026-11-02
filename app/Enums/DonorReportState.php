<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\DonorReport;

/**
 * Where a donor report stands: still to deliver, late, or sent. Derived from
 * `submitted_on` and `due_on` (contracts §14.3), never stored.
 */
enum DonorReportState: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Due = 'due';
    case Overdue = 'overdue';
    case Submitted = 'submitted';

    public static function of(DonorReport $report): self
    {
        return match (true) {
            $report->isSubmitted() => self::Submitted,
            $report->isOverdue() => self::Overdue,
            default => self::Due,
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
            self::Due => StatusTone::Neutral,
            self::Overdue => StatusTone::Critical,
            self::Submitted => StatusTone::Good,
        };
    }
}
