<?php

declare(strict_types=1);

namespace App\Actions\Desk;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Notifications\ActivityRecorder;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Notifications\TracksActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A Desk nudge: "X nudged you about Y", sent in the app to the person
 * holding a piece of the sender's work (user decision, .ai/rules/desk.md).
 *
 * The same item can nudge the same person once a day. The nudge is logged
 * in the activity log (nudge.logged, notifying nobody) and travels as the
 * catalog event nudge.sent, which an administrator can switch off. The
 * caller decides who may be nudged about what; this only sends.
 */
final class SendNudge
{
    public const string Event = 'nudge.sent';

    public function __construct(
        private EventCatalog $catalog,
        private EmployeeRecipients $employees,
        private RecipientFilter $filter,
        private ActivityRecorder $recorder,
    ) {}

    /**
     * @return bool false when nothing was sent (switched off, already nudged today, or nobody to reach)
     */
    public function handle(User $sender, Model&TracksActivity $subject, int $employeeId): bool
    {
        if ($employeeId === $sender->employee_id) {
            return false;
        }

        $catalog = $this->catalog->effective(self::Event);

        if ($catalog === null || ! $catalog['enabled']) {
            return false;
        }

        $userId = $this->employees->usersFor([$employeeId])[$employeeId] ?? null;

        if ($userId === null || $this->filter->apply([$userId], $catalog['module'], $sender->id, [$userId], [$userId]) === []) {
            return false;
        }

        if (! Cache::add("desk-nudge:{$subject->getMorphClass()}:{$subject->getKey()}:{$employeeId}", true, now()->addDay())) {
            return false;
        }

        User::query()->findOrFail($userId)->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action,
            module: $catalog['module'],
            priority: NotificationPriority::Info,
            key: 'notifications.events.'.self::Event,
            params: ['actor' => $sender->name, 'subject' => $subject->activityLabel()],
            subjectType: $subject->getMorphClass(),
            subjectId: (int) $subject->getKey(),
            actorId: $sender->id,
        )));

        $this->recorder->record($subject, 'nudge.logged', properties: [
            'employee' => $this->employees->names([$employeeId])[$employeeId] ?? ['en' => '—', 'ar' => '—'],
        ], notify: false);

        return true;
    }
}
