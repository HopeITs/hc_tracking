<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\WorkingCalendar;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Every change a person makes to their own inbox. Each operation is scoped to
 * the acting user, so a tampered id can never touch someone else's row.
 */
final class Inbox
{
    /**
     * The snooze choices offered in the inbox.
     */
    public const SnoozePresets = ['1h', 'tomorrow', 'next_week'];

    public function __construct(
        private UnreadCounts $counts,
        private NotificationBroadcaster $broadcaster,
    ) {}

    public function markRead(User $user, string $id): void
    {
        $this->markManyRead($user, [$id]);
    }

    public function markUnread(User $user, string $id): void
    {
        UserNotification::query()->forUser($user)->whereKey($id)->update(['read_at' => null]);
        $this->counts->forget($user->id);
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function markManyRead(User $user, array $ids): int
    {
        $updated = UserNotification::query()->forUser($user)->whereKey($ids)->whereNull('read_at')->update(['read_at' => now()]);
        $this->counts->forget($user->id);

        return $updated;
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function markManyUnread(User $user, array $ids): int
    {
        $updated = UserNotification::query()->forUser($user)->whereKey($ids)->whereNotNull('read_at')->update(['read_at' => null]);
        $this->counts->forget($user->id);

        return $updated;
    }

    /**
     * The ids among the given ones that are still unread in my inbox, so an
     * undo only restores what an action actually changed.
     *
     * @param  array<int, string>  $ids
     * @return list<string>
     */
    public function unreadAmong(User $user, array $ids): array
    {
        return UserNotification::query()->forUser($user)->whereKey($ids)->whereNull('read_at')->pluck('id')->all();
    }

    /**
     * Every unread notification I can currently see.
     *
     * @return list<string>
     */
    public function visibleUnreadIds(User $user): array
    {
        return UserNotification::query()->forUser($user)->visible()->whereNull('read_at')->pluck('id')->all();
    }

    public function markAllRead(User $user): int
    {
        $updated = UserNotification::query()->forUser($user)->visible()->whereNull('read_at')->update(['read_at' => now()]);
        $this->counts->forget($user->id);

        return $updated;
    }

    public function snooze(User $user, string $id, string $preset): void
    {
        $this->snoozeMany($user, [$id], $preset);
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function snoozeMany(User $user, array $ids, string $preset): int
    {
        $updated = UserNotification::query()->forUser($user)->whereKey($ids)->update(['snoozed_until' => self::snoozeUntil($preset, CarbonImmutable::now())]);
        $this->counts->forget($user->id);

        return $updated;
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function unsnoozeMany(User $user, array $ids): int
    {
        $updated = UserNotification::query()->forUser($user)->whereKey($ids)->update(['snoozed_until' => null]);
        $this->counts->forget($user->id);

        return $updated;
    }

    /**
     * Bring a snoozed notification back now, as it was (read state kept).
     */
    public function unsnooze(User $user, string $id): void
    {
        $this->unsnoozeMany($user, [$id]);
    }

    /**
     * When a snooze preset ends: in an hour, tomorrow 08:00, or the first
     * working day of next week 08:00.
     */
    public static function snoozeUntil(string $preset, CarbonImmutable $now): CarbonImmutable
    {
        return match ($preset) {
            '1h' => $now->addHour(),
            'tomorrow' => $now->addDay()->setTime(8, 0),
            'next_week' => app(WorkingCalendar::class)->firstWorkingDayOfNextWeek($now)->setTime(8, 0),
            default => throw new InvalidArgumentException("Unknown snooze preset [{$preset}]."),
        };
    }

    /**
     * Wake every notification whose snooze has ended: unread again, moved to
     * the top, pushed again.
     */
    public function wakeDue(): int
    {
        $due = UserNotification::query()
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->get();

        $users = User::query()->whereIn('id', $due->pluck('notifiable_id')->unique()->values())->get()->keyBy('id');

        foreach ($due as $notification) {
            $notification->forceFill(['snoozed_until' => null, 'read_at' => null, 'created_at' => now()])->save();

            $user = $users->get($notification->notifiable_id);

            if ($user !== null) {
                $this->counts->forget($user->id);
                $this->broadcaster->push($user, $notification);
            }
        }

        return $due->count();
    }
}
