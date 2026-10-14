<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Work sitting in one status too long. Only a status change resets the
 * timer, and each stall fires once (its stage is keyed on when it started).
 *
 * Tranches are deliberately not here: a late tranche is already covered by
 * the overdue and escalation chain (DeadlineCatalog), and a second "stalled"
 * alert would tell Financial the same thing twice (user decision 2026-09-23).
 *
 * @phpstan-type Stall array{type: string, id: int, module: string, label: string, status: array{enum: string, value: string}, days: int, stage: string, userIds: list<int>, reviewerRoles: list<string>}
 */
final class StalledScanner implements Scanner
{
    public function __construct(
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->stalledSubjects($today) as $stall) {
            $payload = $this->dispatcher->payload(
                $stall['module'],
                NotificationPriority::Important,
                'notifications.alerts.stalled.'.$stall['type'],
                ['subject' => $stall['label'], 'status' => $stall['status'], 'days' => (string) $stall['days']],
                $stall['type'],
                $stall['id'],
            );

            $sent += $this->dispatcher->send('stalled', $stall['stage'], $payload, $stall['userIds'], $this->resolver->usersWithRoles($stall['reviewerRoles']));
        }

        return $sent;
    }

    /**
     * Every record stalled today.
     *
     * @return list<array{type: string, id: int, module: string, label: string, status: array{enum: string, value: string}|string, days: int, stage: string, userIds: list<int>, reviewerRoles: list<string>}>
     */
    public function stalledSubjects(CarbonImmutable $today): array
    {
        return [
            ...$this->tasks($today, TaskStatus::InProgress, (int) $this->settings->get('stalled_task_in_progress_days')),
            ...$this->tasks($today, TaskStatus::UnderReview, (int) $this->settings->get('stalled_task_under_review_days')),
            ...$this->reports($today, ReportStatus::Draft, (int) $this->settings->get('stalled_report_draft_days')),
            ...$this->reports($today, ReportStatus::Reviewed, (int) $this->settings->get('stalled_report_reviewed_days')),
            ...$this->proposals($today, (int) $this->settings->get('stalled_proposal_days')),
        ];
    }

    /**
     * @return list<Stall>
     */
    private function tasks(CarbonImmutable $today, TaskStatus $status, int $threshold): array
    {
        $tasks = Task::query()->select(['id', 'title', 'created_by', 'updated_at'])->where('status', $status->value)->with('taskAssignees:id,task_id,employee_id')->get();
        $since = $this->statusSince('task', $tasks->modelKeys(), ['task.created', 'task.status_changed']);
        $users = $this->employees->usersFor($tasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee_id'))->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $this->stalls($tasks, $since, $today, $threshold, 'task', 'tasks', $status->value, ['enum' => 'task_status', 'value' => $status->value], [],
            fn (Task $task): array => [...$task->taskAssignees->map(fn ($assignee): ?int => $users[(int) $assignee->employee_id] ?? null)->all(), $task->created_by],
            fn (Task $task): string => $task->title);
    }

    /**
     * @return list<Stall>
     */
    private function reports(CarbonImmutable $today, ReportStatus $status, int $threshold): array
    {
        $reports = Report::query()->select(['id', 'user_id', 'plan_indicator_id', 'updated_at'])->where('status', $status->value)->get();
        $since = $this->statusSince('report', $reports->modelKeys(), ['report.created', 'report.submitted', 'report.approved', 'report.status_changed']);

        return $this->stalls($reports, $since, $today, $threshold, 'report', 'reports', $status->value, ['enum' => 'report_status', 'value' => $status->value], ['MEAL'],
            fn (Report $report): array => [$report->user_id],
            fn (Report $report): string => $report->activityLabel());
    }

    /**
     * @return list<Stall>
     */
    private function proposals(CarbonImmutable $today, int $threshold): array
    {
        $proposals = Proposal::query()->select(['id', 'name', 'status', 'updated_at'])->whereNotIn('status', [ProposalStatus::Completed->value, ProposalStatus::Rejected->value, ProposalStatus::OnHold->value])->get();
        $since = $this->statusSince('proposal', $proposals->modelKeys(), ['proposal.created', 'proposal.status_changed']);

        return array_values($proposals->flatMap(fn (Proposal $proposal): array => $this->stalls(
            collect([$proposal]), $since, $today, $threshold, 'proposal', 'proposals', $proposal->status->value,
            ['enum' => 'proposal_status', 'value' => $proposal->status->value], ['Financial'],
            fn (): array => [], fn (Proposal $proposal): string => $proposal->name,
        ))->all());
    }

    /**
     * When each record's current status began: its latest status activity,
     * else its last update.
     *
     * @param  array<int, int|string>  $ids  model keys
     * @param  list<string>  $events
     * @return Collection<int, string>
     */
    private function statusSince(string $type, array $ids, array $events): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', $type)
            ->whereIn('subject_id', $ids)
            ->whereIn('event', $events)
            ->groupBy('subject_id')
            ->toBase()
            ->selectRaw('subject_id, max(created_at) as since')
            ->pluck('since', 'subject_id');
    }

    /**
     * @template TRecord of Model
     *
     * @param  Collection<int, TRecord>  $records
     * @param  Collection<int, string>  $since
     * @param  array{enum: string, value: string}  $status
     * @param  list<string>  $reviewerRoles
     * @param  callable(TRecord): array<int, int|null>  $people
     * @param  callable(TRecord): string  $label
     * @return list<Stall>
     */
    private function stalls(Collection $records, Collection $since, CarbonImmutable $today, int $threshold, string $type, string $module, string $statusValue, array $status, array $reviewerRoles, callable $people, callable $label): array
    {
        $stalls = [];

        foreach ($records as $record) {
            $start = CarbonImmutable::parse($since[$record->getKey()] ?? $record->getAttribute($record->getUpdatedAtColumn()));
            $days = $this->calendar->workingDaysBetween($start, $today);

            if ($days < $threshold) {
                continue;
            }

            $stalls[] = [
                'type' => $type,
                'id' => (int) $record->getKey(),
                'module' => $module,
                'label' => $label($record),
                'status' => $status,
                'days' => $days,
                'stage' => $statusValue.':'.$start->format('YmdHis'),
                'userIds' => array_values(array_unique(array_map('intval', array_filter($people($record))))),
                'reviewerRoles' => $reviewerRoles,
            ];
        }

        return $stalls;
    }
}
