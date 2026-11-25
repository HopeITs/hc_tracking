<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Programs;

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\DonorProposal;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TransfersTracking;
use App\Support\Locations\MonthlyReportsAtRows;
use App\Support\ProjectPace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;

/**
 * The tracking view of programs/show: one ledger row per project (pace,
 * tasks, last month's report, targets and money), the ranked
 * "needs your attention" list built from it, and the one-word health verdict.
 *
 * Every figure comes from a fixed number of grouped reads, so the project
 * count never adds queries. Report, target and money figures are skipped
 * server-side for viewers without the matching permission.
 *
 * @property int $programId
 * @property-read Program $program  #[Computed] program()
 * @property-read Collection<int, Task> $tasks  #[Computed] tasks()
 * @property-read array<int, array<string, mixed>> $projectLedger  #[Computed] projectLedger()
 * @property-read array<int, array{key: string, tone: string, text: string, label: string, href: string, mine: bool}> $attention  #[Computed] attention()
 * @property-read array{projects: int, active: int, pastDueTasks: int, mine: int, targets: array{total: int, reached: int}|null} $healthFigures  #[Computed] healthFigures()
 *
 * @method int|null taskProjectId(Task $task)
 */
trait TracksProgramHealth
{
    /**
     * Past-due tasks a project carries before that alone flags it. One late
     * chore in a busy project is routine; a pile of them is a signal.
     */
    public const PAST_DUE_TASKS_THRESHOLD = 3;

    /**
     * Whether the viewer may see agreement budgets and transfers: both money
     * permissions, as on the project's own Funding tab.
     */
    public function canViewProgramMoney(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('funding-agreements.view') && $user->can('transfers.view');
    }

    /** Whether a task still waits on someone and its due date has passed. */
    protected function isTaskPastDue(Task $task): bool
    {
        return $task->status !== TaskStatus::Done
            && $task->due_date !== null
            && $task->due_date->lt(CarbonImmutable::today());
    }

    /** The finished month whose monthly reports the page tracks. */
    protected function trackedReportMonth(): CarbonImmutable
    {
        return CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
    }

    /**
     * One row per project with everything a tracker checks.
     *
     * @return array<int, array{
     *     project: Project,
     *     progress: int|null,
     *     elapsed: float|null,
     *     pace: string,
     *     openTasks: int,
     *     pastDueTasks: int,
     *     report: MonthlyReportStatus|null,
     *     reportExpected: bool,
     *     targets: array{total: int, reached: int, percent: float|null}|null,
     *     funding: list<array{currency: string, committed: float, received: float}>|null,
     *     lateTransfers: int,
     *     happenedRisks: int,
     *     needsAttention: bool,
     * }>
     */
    #[Computed]
    public function projectLedger(): array
    {
        $today = CarbonImmutable::today();

        // Y-16 (D-93): the ledger is one line per **place** of the program,
        // which is what the projects directory counts when filtered by it.
        $projects = ProposalLocation::query()
            ->select(['id', 'proposal_id', 'project_id', 'slug', 'status', 'start_date', 'end_date', 'program_id', 'responsible_employee_id', 'city_id', 'city_wide'])
            ->where('program_id', $this->programId)
            ->with(['responsible', 'city', 'centers', 'program:id', 'proposal:id,name'])
            ->withCount([
                'risks as happened_risks_count' => fn ($query) => $query->where('happened', true),
            ])
            ->get()
            ->sortBy(fn (ProposalLocation $row): string => $row->headline())
            ->values();

        $ids = array_map(intval(...), array_values($projects->modelKeys()));
        $taskCounts = $this->taskCountsByProject();
        $reports = $this->reportStatusByProject($projects);
        $targets = $this->targetTotalsByProject($ids);
        [$funding, $lateTransfers] = $this->fundingByProject($projects);

        return $projects
            ->map(function (ProposalLocation $project) use ($taskCounts, $reports, $targets, $funding, $lateTransfers, $today): array {
                $progress = null;

                return [
                    'project' => $project,
                    'progress' => $progress,
                    'elapsed' => ProjectPace::elapsed($project->start_date, $project->end_date, $today),
                    'pace' => ProjectPace::rate($project->status, $project->start_date, $project->end_date, $progress, $today),
                    'openTasks' => $taskCounts[$project->id]['open'] ?? 0,
                    'pastDueTasks' => $taskCounts[$project->id]['pastDue'] ?? 0,
                    'report' => $reports === null ? null : ($reports[$project->id] ?? null),
                    'reportExpected' => $reports !== null && $project->status === ProjectStatus::Active,
                    'targets' => $targets === null ? null : ($targets[$project->id] ?? ['total' => 0, 'reached' => 0, 'percent' => null]),
                    'funding' => $funding === null ? null : ($funding[$project->id] ?? []),
                    'lateTransfers' => $lateTransfers[$project->id] ?? 0,
                    'happenedRisks' => (int) $project->getAttribute('happened_risks_count'),
                ];
            })
            ->map(function (array $row): array {
                $row['needsAttention'] = $this->rowNeedsAttention($row);

                return $row;
            })
            ->all();
    }

    /**
     * Whether a ledger row belongs in the "Needs attention" group: behind or
     * past its end date or slipping, a missing monthly report, or a pile of past-due tasks.
     *
     * A late transfer is the donor's delay, shared by every place the
     * agreement funds and not something the delivery team can act on, so it
     * stays in the attention list for finance and does not flag the card.
     *
     * @param  array<string, mixed>  $row
     */
    protected function rowNeedsAttention(array $row): bool
    {
        return in_array($row['pace'], [...ProjectPace::CRITICAL, ...ProjectPace::WARNING], true)
            || ($row['reportExpected'] && $row['report'] === null)
            || $row['pastDueTasks'] >= self::PAST_DUE_TASKS_THRESHOLD;
    }

    /**
     * Open and past-due task counts per place, from the already loaded Tasks
     * tab collection. Program-direct tasks count under key 0.
     *
     * @return array<int, array{open: int, pastDue: int}>
     */
    protected function taskCountsByProject(): array
    {
        $counts = [];

        foreach ($this->tasks as $task) {
            if ($task->status === TaskStatus::Done) {
                continue;
            }

            $key = $this->taskProjectId($task) ?? 0;
            $counts[$key] ??= ['open' => 0, 'pastDue' => 0];
            $counts[$key]['open']++;

            if ($this->isTaskPastDue($task)) {
                $counts[$key]['pastDue']++;
            }
        }

        return $counts;
    }

    /**
     * Last finished month's monthly report status per place, or null when the
     * viewer may not see reports.
     *
     * A report belongs to a template, and a template reaches a row by its
     * place (Y-16): MonthlyReportsAtRows is that one match.
     *
     * @param  SupportCollection<int, ProposalLocation>  $rows
     * @return array<int, MonthlyReportStatus>|null
     */
    protected function reportStatusByProject(SupportCollection $rows): ?array
    {
        if (auth()->user()?->can('reports.view') !== true) {
            return null;
        }

        $month = $this->trackedReportMonth()->toDateString();
        $statuses = [];

        foreach (app(MonthlyReportsAtRows::class)->reportsByRow($rows, ['id', 'report_template_id', 'period', 'status']) as $rowId => $reports) {
            $report = $reports->first(fn (MonthlyReport $report): bool => $report->period->toDateString() === $month);

            if ($report !== null) {
                $statuses[$rowId] = $report->status;
            }
        }

        return $statuses;
    }

    /**
     * Targets attributed to each place and how many are reached, by the
     * shared achievedValueSql() rule. Null when the viewer may not see targets.
     *
     * @param  list<int>  $locationIds
     * @return array<int, array{total: int, reached: int, percent: float|null}>|null
     */
    protected function targetTotalsByProject(array $locationIds): ?array
    {
        if (auth()->user()?->can('targets.view') !== true) {
            return null;
        }

        return PlanIndicator::query()
            ->whereIn('plan_indicators.proposal_location_id', $locationIds)
            ->select(['plan_indicators.proposal_location_id', 'plan_indicators.target'])
            ->selectRaw(PlanIndicator::achievedValueSql().' as achieved')
            ->toBase()
            ->get()
            ->groupBy('proposal_location_id')
            ->map(function ($rows): array {
                $rated = $rows->filter(fn ($row): bool => (float) $row->target > 0);

                return [
                    'total' => $rows->count(),
                    'reached' => $rated->filter(fn ($row): bool => (float) $row->achieved >= (float) $row->target)->count(),
                    'percent' => $rated->isEmpty() ? null : round($rated->avg(fn ($row): float => min(100, (float) $row->achieved / (float) $row->target * 100)), 1),
                ];
            })
            ->all();
    }

    /**
     * Committed and received money per place per currency, plus each place's
     * late tranches. Money is null (and no money is read) for a viewer
     * without both money permissions.
     *
     * An agreement that funds several places counts in full on each of their
     * rows; the program total in {@see programFunding()} counts it once.
     *
     * @param  SupportCollection<int, ProposalLocation>  $rows
     * @return array{0: array<int, list<array{currency: string, committed: float, received: float}>>|null, 1: array<int, int>}
     */
    protected function fundingByProject(SupportCollection $rows): array
    {
        if (! $this->canViewProgramMoney() || $rows->isEmpty()) {
            return [null, []];
        }

        $agreements = $this->programAgreements($rows);
        $funding = [];
        $late = [];

        foreach ($agreements as $agreement) {
            foreach ($agreement['projectIds'] as $projectId) {
                $currency = $agreement['currency'];
                $funding[$projectId][$currency] ??= ['currency' => $currency, 'committed' => 0.0, 'received' => 0.0];
                $funding[$projectId][$currency]['committed'] += $agreement['committed'];
                $funding[$projectId][$currency]['received'] += $agreement['received'];
                $late[$projectId] = ($late[$projectId] ?? 0) + $agreement['lateTranches'];
            }
        }

        return [array_map(fn (array $rows): array => array_values($rows), $funding), $late];
    }

    /**
     * Every agreement signed against a proposal funding one of these places,
     * carrying the row ids it covers, net money received and its late
     * tranches. A row is of exactly one proposal (D-93), so the link is the
     * row's own `proposal_id` and no `project_proposal` read is needed.
     *
     * @param  SupportCollection<int, ProposalLocation>  $rows
     * @return list<array{id: int, currency: string, committed: float, received: float, lateTranches: int, projectIds: list<int>}>
     */
    protected function programAgreements(SupportCollection $rows): array
    {
        $links = $rows
            ->filter(fn (ProposalLocation $row): bool => $row->proposal_id !== null)
            ->map(fn (ProposalLocation $row): object => (object) ['project_id' => $row->id, 'proposal_id' => $row->proposal_id])
            ->groupBy('proposal_id');

        if ($links->isEmpty()) {
            return [];
        }

        return array_values(DonorProposal::query()
            ->select(['id', 'proposal_id', 'currency', 'budget'])
            ->whereIn('proposal_id', $links->keys())
            ->with('transfers:id,donor_proposal_id,ar_status,transfer_date,scheduled_date,net_amount_received')
            ->get()
            ->map(fn (DonorProposal $agreement): array => [
                'id' => $agreement->id,
                'currency' => $agreement->currency->value,
                'committed' => (float) $agreement->budget,
                'received' => (float) $agreement->transfers->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received),
                'lateTranches' => $agreement->transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late')->count(),
                'projectIds' => array_values(array_unique(array_map(intval(...), $links->get($agreement->proposal_id)?->pluck('project_id')->all() ?? []))),
            ])
            ->all());
    }

    /**
     * Committed and received across the program per currency, each agreement
     * counted once. Null without money permissions.
     *
     * @return list<array{currency: string, committed: float, received: float}>|null
     */
    #[Computed]
    public function programFunding(): ?array
    {
        if (! $this->canViewProgramMoney()) {
            return null;
        }

        $rows = collect(array_map(fn (array $row): ProposalLocation => $row['project'], $this->projectLedger));

        return array_values(collect($this->programAgreements($rows))
            ->groupBy('currency')
            ->map(fn ($group, string $currency): array => [
                'currency' => $currency,
                'committed' => (float) $group->sum('committed'),
                'received' => (float) $group->sum('received'),
            ])
            ->all());
    }

    /**
     * Program-wide totals for the header figures.
     *
     * @return array{projects: int, active: int, pastDueTasks: int, mine: int, targets: array{total: int, reached: int}|null}
     */
    #[Computed]
    public function healthFigures(): array
    {
        $ledger = collect($this->projectLedger);
        $employeeId = auth()->user()?->employee_id;
        $mine = $employeeId === null ? 0 : $this->tasks
            ->filter(fn (Task $task): bool => $this->isTaskPastDue($task) && $task->taskAssignees->contains(fn (TaskAssignee $assignee): bool => (int) $assignee->employee_id === (int) $employeeId))
            ->count();
        $canViewTargets = auth()->user()?->can('targets.view') === true;

        return [
            'projects' => $ledger->count(),
            'active' => $ledger->filter(fn (array $row): bool => $row['project']->status === ProjectStatus::Active)->count(),
            'pastDueTasks' => $this->tasks->filter(fn (Task $task): bool => $this->isTaskPastDue($task))->count(),
            'mine' => $mine,
            'targets' => $canViewTargets ? [
                'total' => (int) $ledger->sum(fn (array $row): int => $row['targets']['total'] ?? 0),
                'reached' => (int) $ledger->sum(fn (array $row): int => $row['targets']['reached'] ?? 0),
            ] : null,
        ];
    }

    /**
     * The ranked "needs your attention" list: alarms before warnings, and
     * within each, what is the viewer's own first. Each item is one plain
     * sentence and one link to the place where it gets fixed.
     *
     * @return array<int, array{key: string, tone: string, text: string, label: string, href: string, mine: bool}>
     */
    #[Computed]
    public function attention(): array
    {
        $items = [];
        $ledger = collect($this->projectLedger);
        $figures = $this->healthFigures;
        $tasksTab = route('programs.show', ['program' => $this->program, 'tab' => 'tasks']);
        $month = $this->trackedReportMonth();

        if ($figures['mine'] > 0) {
            $items[] = ['key' => 'my_tasks', 'tone' => 'critical', 'mine' => true, 'href' => $tasksTab, 'label' => __('programs.attention_open_tasks'),
                'text' => trans_choice('programs.attention_my_tasks_past_due', $figures['mine'], ['count' => $figures['mine']])];
        }

        $othersPastDue = $figures['pastDueTasks'] - $figures['mine'];
        if ($othersPastDue > 0) {
            $items[] = ['key' => 'tasks', 'tone' => 'critical', 'mine' => false, 'href' => $tasksTab, 'label' => __('programs.attention_open_tasks'),
                'text' => trans_choice($figures['mine'] > 0 ? 'programs.attention_other_tasks_past_due' : 'programs.attention_tasks_past_due', $othersPastDue, ['count' => $othersPastDue])];
        }

        foreach ($ledger as $row) {
            $project = $row['project'];
            $projectHref = route('projects.show', $project);
            $isMine = auth()->user()?->employee_id !== null && (int) $project->responsible_employee_id === (int) auth()->user()->employee_id;

            if ($row['pace'] === 'past_end') {
                $items[] = ['key' => 'past_end', 'tone' => 'critical', 'mine' => $isMine, 'href' => $projectHref, 'label' => __('programs.attention_open_project'),
                    'text' => __('programs.attention_past_end', ['project' => $project->headline()])];
            } elseif ($row['pace'] === 'behind') {
                $items[] = ['key' => 'behind', 'tone' => 'critical', 'mine' => $isMine, 'href' => $projectHref, 'label' => __('programs.attention_open_project'),
                    'text' => __('programs.attention_behind', ['project' => $project->headline(), 'progress' => $row['progress'], 'expected' => (int) round((float) $row['elapsed'])])];
            } elseif ($row['pace'] === 'slipping') {
                $items[] = ['key' => 'slipping', 'tone' => 'warn', 'mine' => $isMine, 'href' => $projectHref, 'label' => __('programs.attention_open_project'),
                    'text' => __('programs.attention_slipping', ['project' => $project->headline()])];
            }

            if ($row['lateTransfers'] > 0) {
                $items[] = ['key' => 'transfers', 'tone' => 'warn', 'mine' => false, 'href' => route('transfers.index', ['stage' => 'late', 'search' => $project->headline()]), 'label' => __('programs.attention_open_transfers'),
                    'text' => trans_choice('programs.attention_late_transfers', $row['lateTransfers'], ['count' => $row['lateTransfers'], 'project' => $project->headline()])];
            }
        }

        $missing = $ledger->filter(fn (array $row): bool => $row['reportExpected'] && $row['report'] === null)->count();
        if ($missing > 0) {
            $items[] = ['key' => 'reports_missing', 'tone' => 'warn', 'mine' => false, 'href' => route('monthly-reports.index', ['month' => $month->format('Y-m'), 'status' => '']), 'label' => __('programs.attention_open_reports'),
                'text' => trans_choice('programs.attention_reports_missing', $missing, ['count' => $missing, 'month' => $month->translatedFormat('F')])];
        }

        $awaiting = $ledger->filter(fn (array $row): bool => $row['report'] === MonthlyReportStatus::Submitted)->count();
        if ($awaiting > 0 && auth()->user()?->can('reports.approve') === true) {
            $items[] = ['key' => 'reports_validate', 'tone' => 'warn', 'mine' => true, 'href' => route('monthly-reports.index', ['month' => $month->format('Y-m')]), 'label' => __('programs.attention_validate_reports'),
                'text' => trans_choice('programs.attention_reports_to_validate', $awaiting, ['count' => $awaiting, 'month' => $month->translatedFormat('F')])];
        }

        $happened = (int) $ledger->sum('happenedRisks');
        if ($happened > 0) {
            $items[] = ['key' => 'risks', 'tone' => 'warn', 'mine' => false, 'href' => route('risks.index', ['materialisedOnly' => 1]), 'label' => __('programs.attention_open_risks'),
                'text' => trans_choice('programs.attention_risks_happened', $happened, ['count' => $happened])];
        }

        return collect($items)
            ->sortBy(fn (array $item): array => [$item['tone'] === 'critical' ? 0 : 1, $item['mine'] ? 0 : 1])
            ->values()
            ->all();
    }

    /**
     * The one-word read on the program: at_risk with any alarm, attention
     * with only warnings, on_track otherwise; null with no project to judge.
     */
    #[Computed]
    public function healthVerdict(): ?string
    {
        if ($this->projectLedger === []) {
            return null;
        }

        $tones = array_column($this->attention, 'tone');

        return match (true) {
            in_array('critical', $tones, true) => 'at_risk',
            $tones !== [] => 'attention',
            default => 'on_track',
        };
    }
}
