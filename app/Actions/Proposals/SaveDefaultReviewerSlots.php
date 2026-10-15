<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ReviewerSlot;
use App\Models\HrPosition;
use App\Models\User;
use App\Notifications\Alerts\NotificationSettings;
use App\Support\Slots;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Store the HR position behind each default-reviewer slot (contracts §9,
 * notification_settings `proposal_default_reviewers`). A position is stored,
 * never a person; nothing already invited changes (never retroactive).
 *
 * Moving the MEAL Manager slot is logged on the acting user's account and
 * tells the previous and the new holder (notification_settings.meal_manager_changed).
 *
 * Error keys: `reviewerPositions.{slot}`.
 */
final class SaveDefaultReviewerSlots
{
    public function __construct(private NotificationSettings $settings) {}

    /**
     * @param  array<string, int|null>  $positions  slot value => HR position id
     */
    public function handle(array $positions, User $actor): void
    {
        Gate::forUser($actor)->authorize('notification-settings.manage');

        $values = [];

        foreach (ReviewerSlot::defaultSlots() as $slot) {
            $values[$slot->value] = $positions[$slot->value] ?? null;
        }

        $chosen = array_values(array_filter($values, fn (?int $id): bool => $id !== null));
        $known = $chosen === [] ? [] : HrPosition::query()->whereKey($chosen)->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        $unknown = array_filter($values, fn (?int $id): bool => $id !== null && ! in_array($id, $known, true));

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(fn (string $slot): string => "reviewerPositions.{$slot}", array_keys($unknown)),
                array_fill(0, count($unknown), __('notifications.admin.default_reviewers.unknown_position')),
            ));
        }

        $previousPosition = Slots::positionIds()['meal_manager'];
        $previousHolder = Slots::holderOf('meal_manager');

        $this->settings->save(['proposal_default_reviewers' => $values]);

        if ($previousPosition === ($positions[ReviewerSlot::MealManager->value] ?? null)) {
            return;
        }

        $actor->recordActivity('notification_settings.meal_manager_changed', properties: [
            'employee_id' => Slots::holderOf('meal_manager'),
            'previous_employee_id' => $previousHolder,
        ]);
    }
}
