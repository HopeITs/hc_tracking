<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Risk;
use App\Notifications\EmployeeRecipients;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Monday-morning (first working day) picture per program.
 */
final class WeeklyDigestScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private OffTrackIndicatorScanner $offTrack,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->firstWorkingDayOnOrAfter($this->calendar->startOfIsoWeek($today))->isSameDay($today)) {
            return 0;
        }

        $week = $now->format('o-\WW');
        $stats = $this->statsByProgram($today, $now);
        $programs = Program::query()->whereIn('id', array_keys($stats))->get(['id', 'manager_id']);
        $managers = $this->employees->usersFor($programs->pluck('manager_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $sent = 0;

        foreach ($programs as $program) {
            $managerId = $managers[(int) $program->manager_id] ?? null;

            if ($managerId === null) {
                continue;
            }

            $payload = $this->digest('notifications.digests.weekly_program', ['program' => $program->name, ...$stats[$program->id]], 'program', $program->id, ['route' => 'programs.show', 'parameters' => ['program' => $program->slug]]);
            $sent += $this->dispatcher->send('weekly_digest', $week, $payload, [$managerId], [], [$managerId]);
        }

        $totals = array_reduce($stats, fn (array $carry, array $row): array => array_map(fn (string $key): int => $carry[$key] + $row[$key], array_combine(array_keys($carry), array_keys($carry))), ['overdue' => 0, 'off_track' => 0, 'open_risks' => 0, 'upcoming' => 0]);

        foreach ($this->resolver->usersWithRoles(['General Manager']) as $userId) {
            $payload = $this->digest('notifications.digests.weekly_all', ['programs' => count($stats), ...$totals], 'user', $userId, ['route' => 'dashboard']);
            $sent += $this->dispatcher->send('weekly_digest', $week, $payload, [$userId], [], [$userId]);
        }

        return $sent;
    }

    /**
     * @return array<int, array{overdue: int, off_track: int, open_risks: int, upcoming: int}>
     */
    private function statsByProgram(CarbonImmutable $today, CarbonImmutable $now): array
    {
        $empty = ['overdue' => 0, 'off_track' => 0, 'open_risks' => 0, 'upcoming' => 0];
        $stats = [];

        foreach ($this->catalog->items($today->addDays(14)) as $item) {
            $programId = $this->catalog->programIdFor($item);

            if ($programId === null) {
                continue;
            }

            $stats[$programId] ??= $empty;
            $stats[$programId][$item->dueDate->lt($today) ? 'overdue' : 'upcoming']++;
        }

        Indicator::query()->with('progress')->get()->each(function (Indicator $indicator) use (&$stats, $empty, $now): void {
            $programId = $indicator->activityProgramId();

            if ($programId !== null && $this->offTrack->isOffPace($indicator, $now)) {
                $stats[$programId] ??= $empty;
                $stats[$programId]['off_track']++;
            }
        });

        Risk::query()
            ->join('projects', 'projects.id', '=', 'risks.project_id')
            ->where('risks.happened', true)
            ->where(fn ($query) => $query->whereNull('risks.solution')->orWhere('risks.solution', ''))
            ->groupBy('projects.program_id')
            ->toBase()
            ->selectRaw('projects.program_id, count(*) as aggregate')
            ->pluck('aggregate', 'program_id')
            ->each(function (int|string $count, int|string $programId) use (&$stats, $empty): void {
                $stats[(int) $programId] ??= $empty;
                $stats[(int) $programId]['open_risks'] = (int) $count;
            });

        return $stats;
    }

    /**
     * @param  array<string, int|string>  $params
     * @param  array{route: string, parameters?: array<string, string>}  $link
     */
    private function digest(string $key, array $params, string $subjectType, int $subjectId, array $link): NotificationPayload
    {
        return new NotificationPayload(
            kind: NotificationKind::Digest,
            module: 'system',
            priority: NotificationPriority::Info,
            key: $key,
            params: array_map('strval', $params),
            subjectType: $subjectType,
            subjectId: $subjectId,
            link: $link,
        );
    }
}
