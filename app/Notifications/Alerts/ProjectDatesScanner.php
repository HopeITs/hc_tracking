<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\EventImportance;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\Recipients\OversightRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * `project.started` / `project.ended` (contracts.md §15.2): an active
 * place's start and end dates, counted in its own working week (D-22). A date
 * on a non-working day goes out the next working day; each run catches up
 * every date since the previous working day. The stage is the date, so a
 * moved date re-arms the notice.
 *
 * Y-16 (D-93): the subject is a **location row**, and the event keys keep
 * their names. Two rows may stand at one place (D-77), where a project used
 * to produce one notice: a place gets one notice per person and day, so the
 * place and the date are the ledger's stage and the first row of the place
 * is the subject.
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

        $rows = $this->rowsDatedAround($today, $events);
        $hits = $this->hits($rows, $today, $events);

        if ($hits === []) {
            return 0;
        }

        $activatedOn = $this->activationDays(array_values(array_unique(array_column($hits, 'locationId'))));
        $direct = $this->directUserIds($rows);
        $seen = [];
        $sent = 0;

        foreach ($hits as $hit) {
            /** @var ProposalLocation $row */
            $row = $rows->find($hit['locationId']);
            $date = $hit['date'];

            if ($hit['key'] === 'project.started' && ($activatedOn[$row->id] ?? null)?->gt($date)) {
                continue;
            }

            // One notice per place, key and day (D-77): the rows of one place
            // are the same work to the people hearing about it.
            $placeKey = $row->place()->key().'|'.$hit['key'].'|'.$date->toDateString();

            if (isset($seen[$placeKey])) {
                continue;
            }

            $seen[$placeKey] = true;

            [, $rule, $message] = self::Events[$hit['key']];
            $oversight = $events[$hit['key']] === EventImportance::Immediate
                ? $this->oversight->userIdsFor($hit['key'], $row->id)
                : [];

            $payload = $this->dispatcher->payload(
                'projects',
                NotificationPriority::Info,
                $message,
                ['subject' => $row->headline(), 'date' => ['date' => $date->toDateString()]],
                'proposal_location',
                $row->id,
            );

            // The stage carries the place, so the second row of a place finds
            // the day already claimed even across runs.
            $sent += $this->dispatcher->send($rule, $row->place()->key().':'.$date->toDateString(), $payload, $direct[$row->id] ?? [], $oversight);
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
     * @return Collection<int, ProposalLocation>
     */
    private function rowsDatedAround(CarbonImmutable $today, array $events): Collection
    {
        $from = $today->subDays(self::LookbackDays)->toDateString();
        $to = $today->toDateString();

        return ProposalLocation::query()
            ->notPaused()
            ->where('proposal_locations.status', ProjectStatus::Active->value)
            ->where(function ($query) use ($events, $from, $to): void {
                foreach (array_keys($events) as $key) {
                    $query->orWhereBetween('proposal_locations.'.self::Events[$key][0], [$from, $to]);
                }
            })
            ->with(['centers', 'city:id,name_en,name_ar', 'program:id', 'proposal:id,name'])
            ->get(['id', 'proposal_id', 'project_id', 'city_id', 'city_wide', 'program_id', 'responsible_employee_id', 'start_date', 'end_date', 'working_days']);
    }

    /**
     * The dates that fall in each row's window: after its previous working
     * day, up to today, and only when today is one of its working days.
     *
     * @param  Collection<int, ProposalLocation>  $rows
     * @param  array<string, EventImportance>  $events
     * @return list<array{locationId: int, key: string, date: CarbonImmutable}>
     */
    private function hits(Collection $rows, CarbonImmutable $today, array $events): array
    {
        $hits = [];

        foreach ($rows as $row) {
            $calendar = $this->calendar->forDays($row->working_days);

            if (! $calendar->isWorkingDay($today)) {
                continue;
            }

            $previous = $calendar->lastWorkingDayOnOrBefore($today->subDay());

            foreach (array_keys($events) as $key) {
                $value = $row->{self::Events[$key][0]};
                $date = $value === null ? null : CarbonImmutable::parse($value)->startOfDay();

                if ($date !== null && $date->gt($previous) && $date->lte($today)) {
                    $hits[] = ['locationId' => $row->id, 'key' => $key, 'date' => $date];
                }
            }
        }

        return $hits;
    }

    /**
     * The day each place last became active: a `project.activated` activity
     * (T08) or a status change to active, recorded against the row.
     *
     * @param  list<int>  $locationIds
     * @return array<int, CarbonImmutable>
     */
    private function activationDays(array $locationIds): array
    {
        return Activity::query()
            ->where('subject_type', 'proposal_location')
            ->whereIn('subject_id', $locationIds)
            ->where(fn ($query) => $query
                ->where('event', 'project.activated')
                ->orWhere(fn ($query) => $query->where('event', 'project.status_changed')->where('changes->status[1]', ProjectStatus::Active->value)))
            ->toBase()
            ->selectRaw('subject_id, max(created_at) as activated_at')
            ->groupBy('subject_id')
            ->pluck('activated_at', 'subject_id')
            ->mapWithKeys(fn (string $activatedAt, int|string $locationId): array => [(int) $locationId => CarbonImmutable::parse($activatedAt)->startOfDay()])
            ->all();
    }

    /**
     * Responsible employee and program manager of each row, as users, in two
     * queries.
     *
     * @param  Collection<int, ProposalLocation>  $rows
     * @return array<int, list<int>> row id => user ids
     */
    private function directUserIds(Collection $rows): array
    {
        $managers = Program::query()
            ->whereIn('id', $rows->pluck('program_id')->filter()->unique()->all())
            ->pluck('manager_id', 'id')
            ->all();

        $employeeIds = [];

        foreach ($rows as $row) {
            $employeeIds[$row->id] = array_values(array_filter([
                $row->responsible_employee_id,
                isset($managers[$row->program_id]) ? (int) $managers[$row->program_id] : null,
            ]));
        }

        $users = $this->employees->usersFor(array_merge(...array_values($employeeIds)));

        return array_map(
            fn (array $ids): array => array_values(array_unique(array_filter(array_map(fn (int $id): ?int => $users[$id] ?? null, $ids)))),
            $employeeIds,
        );
    }
}
