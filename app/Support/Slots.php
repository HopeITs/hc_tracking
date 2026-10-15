<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReviewerSlot;
use App\Notifications\Alerts\NotificationSettings;
use InvalidArgumentException;

/**
 * The one resolver for the named organisational slots (contracts.md §9):
 * MEAL Manager, Finance Manager, Projects & Fundraising Manager and CEO.
 *
 * A slot stores an HR position, never a person, in notification_settings
 * `proposal_default_reviewers`; the person is whoever holds that position now.
 */
final class Slots
{
    /**
     * The HR employee id holding the slot right now, or null when it is
     * vacant: no position configured, no active contract on it, or its holder
     * is inactive in HR. Every caller (reviewers, the D-09a reopen fallback,
     * MEAL routing) reads vacancy the same way, and none falls back to Super
     * Admin (D-20a).
     *
     * @param  string  $slot  meal_manager | finance_manager | fundraising_manager | ceo
     *
     * @throws InvalidArgumentException for any other slot
     */
    public static function holderOf(string $slot): ?int
    {
        $case = ReviewerSlot::tryFrom($slot);

        if ($case === null || ! $case->isDefault()) {
            throw new InvalidArgumentException("[{$slot}] is not a position slot.");
        }

        $positionId = self::positionIds()[$slot] ?? null;
        $holder = $positionId === null ? null : EmployeeProfile::holderOfPosition($positionId);

        if ($holder === null || ! (EmployeeProfile::for($holder)['is_active'] ?? false)) {
            return null;
        }

        return $holder;
    }

    /**
     * The configured HR position of each slot, null when unset.
     *
     * @return array<string, int|null>
     */
    public static function positionIds(): array
    {
        $stored = app(NotificationSettings::class)->get('proposal_default_reviewers');
        $stored = is_array($stored) ? $stored : [];
        $ids = [];

        foreach (ReviewerSlot::defaultSlots() as $slot) {
            $value = $stored[$slot->value] ?? null;
            $ids[$slot->value] = is_numeric($value) ? (int) $value : null;
        }

        return $ids;
    }
}
