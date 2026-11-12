<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a monthly plan update stands (2026-11-11 review chain): the
 * project manager sends it; the governorate MEAL officer approves it; the
 * MEAL manager reviews it; the program manager marks it seen. A return
 * sends it back to the project manager (Returned) or, from the MEAL
 * manager, to the officer (Sent again), keeping everything entered.
 */
enum PlanUpdateStatus: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Sent = 'sent';
    case OfficerApproved = 'officer_approved';
    case Reviewed = 'reviewed';
    case Seen = 'seen';
    case Returned = 'returned';

    /**
     * Whether the project manager may change it.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    /**
     * Approved by the MEAL officer (or further): what lets the next month be sent.
     */
    public function isOfficerApproved(): bool
    {
        return in_array($this, [self::OfficerApproved, self::Reviewed, self::Seen], true);
    }

    /**
     * Whether its weeks count on the live plan: sent, not returned.
     */
    public function isOnThePlan(): bool
    {
        return ! in_array($this, [self::Draft, self::Returned], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Neutral,
            self::Sent, self::OfficerApproved, self::Reviewed => StatusTone::Active,
            self::Seen => StatusTone::Good,
            self::Returned => StatusTone::Warn,
        };
    }
}
