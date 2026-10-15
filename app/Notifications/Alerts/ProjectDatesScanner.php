<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\EventImportance;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\Recipients\OversightRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * `project.started` / `project.ended` (contracts.md §15.2): an active
 * project's start and end dates, counted in the project's own working week
 * (D-22). A date on a non-working day goes out the next working day; each run
 * catches up every date since the previous working day. The stage is the
 * date, so a moved date re-arms the notice.
 */
final class ProjectDatesScanner implements Scanner
{
    /**
     * Catalog key => [date column, ledger rule, message key].
     */
    private const Events = [
        'project.started' => ['start_date', 'project_started', 'notifications.alerts.project_started'],
        'project.ended' => ['end_date', 'project_ended', 'notifications.alerts.project_ended'],
    ];

    /**
     * Farther back than any working week can push a date.
     */
    private const LookbackDays = 7;

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
        $events = $this->enabledEvents();

        if ($events === []) {
            return 0;
        }

        $projects = $this->projectsDatedAround($today, $events);
        $hits = $this->hits($projects, $today, $events);

        if ($hits === []) {
            return 0;
        }

        $activatedOn = $this->activationDays(array_values(array_unique(array_column($hits, 'projectId'))));
        $direct = $this->directUserIds($projects);
        $sent = 0;

        foreach ($hits as $hit) {
            /** @var Project $project */
            $project = $projects->find($hit['projectId']);
            $date = $hit['date'];

            if ($hit['key'] === 'project.started' && ($activatedOn[$project->id] ?? null)?->gt($date)) {
                continue;
            }

            [, $rule, $message] = self::Events[$hit['key']];
            $oversight = $events[$hit['key']] === EventImportance::Immediate
                ? $this->oversight->userIdsFor($hit['key'], $project->id)
                : [];

            $payload = $this->dispatcher->payload(
                'projects',
                NotificationPriority::Info,
                $message,
                ['subject' => $project->name, 'date' => ['date' => $date->toDateString()]],
                'project',
                $project->id,
            );

            $sent += $this->dispatcher->send($rule, $date->toDateString(), $payload, $direct[$project->id] ?? [], $oversight);
        }

        return $sent;
    }

    /**
     * @return array<string, EventImportance> enabled catalog key => effective importance
     */
    private function enabledEvents(): array
    {
        $events = [];

        foreach (array_keys(self::Events) as $key) {
            $event = $this->catalog->effective($key);

            if ($event !== null && $event['enabled']) {
                $events[$key] = $event['importance'];
            }
        }

        return $events;
    }

    /**
     * @param  array<string, EventImportance>  $events
     * @return Collection<int, Project>
     */
    private function projectsDatedAround(CarbonImmutable $today, array $events): Collection
    {
        $from = $today->subDays(self::LookbackDays)->toDateString();
        $to = $today->toDateString();

        return Project::query()
            ->where('status', ProjectStatus::Active->value)
            ->where(function ($query) use ($events, $from, $to): void {
                foreach (array_keys($events) as $key) {
                    $query->orWhereBetween(self::Events[$key][0], [$from, $to]);
                }
            })
            ->get(['id', 'name', 'user_id', 'program_id', 'start_date', 'end_date', 'working_days']);
    }

    /**
     * The dates that fall in each project's window: after its previous
     * working day, up to today, and only when today is one of its working days.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, EventImportance>  $events
     * @return list<array{projectId: int, key: string, date: CarbonImmutable}>
     */
    private function hits(Collection $projects, CarbonImmutable $today, array $events): array
    {
        $hits = [];

        foreach ($projects as $project) {
            $calendar = $this->calendar->forProject($project);

            if (! $calendar->isWorkingDay($today)) {
                continue;
            }

            $previous = $calendar->lastWorkingDayOnOrBefore($today->subDay());

            foreach (array_keys($events) as $key) {
                $value = $project->{self::Events[$key][0]};
                $date = $value === null ? null : CarbonImmutable::parse($value)->startOfDay();

                if ($date !== null && $date->gt($previous) && $date->lte($today)) {
                    $hits[] = ['projectId' => $project->id, 'key' => $key, 'date' => $date];
                }
            }
        }

        return $hits;
    }

    /**
     * The day each project last became active: a `project.activated`
     * activity (T08) or a status change to active.
     *
     * @param  list<int>  $projectIds
     * @return array<int, CarbonImmutable>
     */
    private function activationDays(array $projectIds): array
    {
        return Activity::query()
            ->where('subject_type', 'project')
            ->whereIn('subject_id', $projectIds)
            ->where(fn ($query) => $query
                ->where('event', 'project.activated')
                ->orWhere(fn ($query) => $query->where('event', 'project.status_changed')->where('changes->status[1]', ProjectStatus::Active->value)))
            ->toBase()
            ->selectRaw('subject_id, max(created_at) as activated_at')
            ->groupBy('subject_id')
            ->pluck('activated_at', 'subject_id')
            ->mapWithKeys(fn (string $activatedAt, int|string $projectId): array => [(int) $projectId => CarbonImmutable::parse($activatedAt)->startOfDay()])
            ->all();
    }

    /**
     * Owner and program manager of each project, as users, in two queries.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, list<int>> project id => user ids
     */
    private function directUserIds(Collection $projects): array
    {
        $managers = Program::query()
            ->whereIn('id', $projects->pluck('program_id')->unique()->all())
            ->pluck('manager_id', 'id')
            ->all();

        $employeeIds = [];

        foreach ($projects as $project) {
            $employeeIds[$project->id] = array_values(array_filter([
                $project->user_id,
                isset($managers[$project->program_id]) ? (int) $managers[$project->program_id] : null,
            ]));
        }

        $users = $this->employees->usersFor(array_merge(...array_values($employeeIds)));

        return array_map(
            fn (array $ids): array => array_values(array_unique(array_filter(array_map(fn (int $id): ?int => $users[$id] ?? null, $ids)))),
            $employeeIds,
        );
    }
}
