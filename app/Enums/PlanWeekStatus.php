<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What happened to a batch in one week (Rami's 8 statuses, 2026-11-11).
 * Not started, Rescheduled, Suspended and Cancelled need a reason;
 * Rescheduled also names the new week.
 */
enum PlanWeekStatus: string
{
    use HasTranslatedLabel;

    case Started = 'started';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Conducted = 'conducted';
    case Rescheduled = 'rescheduled';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case NotStarted = 'not_started';

    public function needsReason(): bool
    {
        return in_array($this, [self::NotStarted, self::Rescheduled, self::Suspended, self::Cancelled], true);
    }

    /**
     * The batch ran that week.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Started, self::Ongoing, self::Completed, self::Conducted], true);
    }

    /**
     * The batch finished that week (a run ended or a one-off happened).
     */
    public function isFinish(): bool
    {
        return $this === self::Completed || $this === self::Conducted;
    }

    /**
     * Drawing order on the live plan: the most telling status wins a week.
     */
    public function rank(): int
    {
        return array_search($this, [self::Cancelled, self::Suspended, self::Rescheduled, self::NotStarted, self::Started, self::Ongoing, self::Completed, self::Conducted], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
