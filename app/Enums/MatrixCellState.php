<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What one month of a Monthly Matrix row shows (T18). Display only: never
 * stored. Only `Approved` cells are counted (contracts §14).
 */
enum MatrixCellState: string
{
    use HasTranslatedLabel;

    /** The monthly report is validated: the figure is counted. */
    case Approved = 'approved';

    /** The report is submitted: the figure is shown but never summed. */
    case Pending = 'pending';

    /** A started month whose report is a draft or does not exist yet. */
    case NotSubmitted = 'not_submitted';

    /** The month has not started. */
    case Upcoming = 'upcoming';

    /** The report exists but the indicator is not due that month. */
    case NotDue = 'not_due';

    /**
     * The icon that carries the state beside its text, so colour never
     * stands alone.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Approved => 'check-circle',
            self::Pending => 'clock',
            self::NotSubmitted => 'pencil-square',
            self::Upcoming => 'calendar',
            self::NotDue => 'minus',
        };
    }
}
