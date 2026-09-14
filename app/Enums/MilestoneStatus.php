<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Whether a milestone was reached, and if not, why it is no longer pending.
 *
 * The value is what lands in `milestones.status` (VARCHAR(50)). Values are
 * lower case and casing is part of the value.
 */
enum MilestoneStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Reached = 'reached';
    case Missed = 'missed';
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
