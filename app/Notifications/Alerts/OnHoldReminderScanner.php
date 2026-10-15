<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\EventImportance;
use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\Recipients\OversightRecipients;
use Carbon\CarbonImmutable;

/**
 * `proposal.on_hold_reminder` (contracts.md §15.2): a proposal still on hold
 * whose `status_remind_on` has come. Direct = the owners of its linked
 * projects; its editors (holders of `proposals.update`) and oversight are
 * told too. Once per remind date: the stage is the date, so a new date
 * re-arms it, and a proposal that left hold drops out of the query.
 */
final class OnHoldReminderScanner implements Scanner
{
    private const Key = 'proposal.on_hold_reminder';

    private const EditPermission = 'proposals.update';

    public function __construct(
        private WorkingCalendar $calendar,
        private EventCatalog $catalog,
        private OversightRecipients $oversight,
        private EmployeeRecipients $employees,
        private AlertDispatcher $dispatcher,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();
        $event = $this->catalog->effective(self::Key);

        if ($event === null || ! $event['enabled'] || ! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $proposals = Proposal::query()
            ->where('status', ProposalStatus::OnHold->value)
            ->whereDate('status_remind_on', '<=', $today->toDateString())
            ->with('projects:projects.id,projects.user_id')
            ->get(['id', 'name', 'status_remind_on']);

        if ($proposals->isEmpty()) {
            return 0;
        }

        $owners = $this->employees->usersFor($proposals->flatMap(fn (Proposal $proposal) => $proposal->projects->pluck('user_id'))->filter()->map(fn (mixed $id): int => (int) $id)->all());
        $editors = $this->editorUserIds();
        $sent = 0;

        foreach ($proposals as $proposal) {
            $remindOn = CarbonImmutable::parse($proposal->status_remind_on)->toDateString();
            $direct = array_values(array_unique(array_filter($proposal->projects->map(fn (Project $project): ?int => $owners[(int) $project->user_id] ?? null)->all())));
            $oversight = $event['importance'] === EventImportance::Immediate ? $this->oversight->userIdsFor(self::Key) : [];

            $payload = $this->dispatcher->payload(
                'proposals',
                NotificationPriority::Important,
                'notifications.alerts.on_hold_reminder',
                ['subject' => $proposal->name, 'date' => ['date' => $remindOn]],
                'proposal',
                $proposal->id,
            );

            $sent += $this->dispatcher->send('on_hold_reminder', $remindOn, $payload, $direct, [...$editors, ...$oversight]);
        }

        return $sent;
    }

    /**
     * Holders of `proposals.update`, never Super Admin (D-20a).
     *
     * @return list<int>
     */
    private function editorUserIds(): array
    {
        return array_values(User::permission(self::EditPermission)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', EventCatalog::SuperAdminRole))
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }
}
