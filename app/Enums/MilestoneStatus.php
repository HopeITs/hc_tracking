<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a milestone stands in its own delivery lifecycle.
 *
 * The value is what lands in `milestones.status` (VARCHAR(50)). Values are
 * lower case and casing is part of the value.
 *
 * "Missed" and "overdue" are NOT members here — they are derived, not stored.
 * See Milestone::isMissed() and Milestone::isOverdue().
 */
enum MilestoneStatus: string
{
    use HasTranslatedLabel;

    case Planned = 'planned';
    case Ongoing = 'ongoing';
    case Reached = 'reached';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';

    /**
     * All milestone status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
