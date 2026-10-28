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
 * A Desk poke (shown as "Poke"; the class and events keep the old nudge
 * name so stored settings keep working): "X poked you about Y: note", sent
 * in the app and live to a person on the sender's work or team (user
 * decision 2026-10-27).
 *
 * The subject and the note are both optional. The same sender can poke the
 * same person once a day. A poke about a subject is logged on it
 * (nudge.logged, notifying nobody). It travels as the catalog event
 * nudge.sent, which an administrator can switch off. The caller decides who
 * may be poked about what; this only sends.
 */
final class SendNudge
{
    public const string Event = 'nudge.sent';

    public const int NoteLimit = 200;

    public function __construct(
        private EventCatalog $catalog,
        private EmployeeRecipients $employees,
        private RecipientFilter $filter,
        private ActivityRecorder $recorder,
    ) {}

    /**
     * @return bool false when nothing was sent (switched off, already poked today, or nobody to reach)
     */
    public function handle(User $sender, (Model&TracksActivity)|null $subject, int $employeeId, ?string $note = null): bool
    {
        $note = self::cleanNote($note);

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

        if (! Cache::add("desk-poke:{$sender->id}:{$employeeId}", true, now()->addDay())) {
            return false;
        }

        $line = ($subject === null ? 'plain' : 'body').($note === null ? '' : '_note');

        User::query()->findOrFail($userId)->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action,
            module: $catalog['module'],
            priority: NotificationPriority::Info,
            key: 'notifications.events.'.self::Event.'.'.$line,
            params: array_filter(['actor' => $sender->name, 'subject' => $subject?->activityLabel(), 'note' => $note], fn (?string $value): bool => $value !== null),
            subjectType: $subject?->getMorphClass(),
            subjectId: $subject === null ? null : (int) $subject->getKey(),
            actorId: $sender->id,
        )));

        if ($subject === null) {
            return true;
        }

        $this->recorder->record($subject, 'nudge.logged', properties: [
            'employee' => $this->employees->names([$employeeId])[$employeeId] ?? ['en' => '—', 'ar' => '—'],
        ], notify: false);

        return true;
    }

    /**
     * The note as plain text: tags stripped, whitespace trimmed, at most
     * NoteLimit characters; empty becomes null.
     */
    public static function cleanNote(?string $note): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $note)) ?? '');

        return $note === '' ? null : mb_substr($note, 0, self::NoteLimit);
    }
}
