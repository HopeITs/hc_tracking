<?php

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.dashboard')] class extends Component {
    /**
     * The most rows the attention queue shows at once, in any view.
     */
    private const AttentionLimit = 8;

    /**
     * How many rows of the "All" view are held for materialised risks.
     */
    private const RiskSlots = 2;

    /**
     * Everything that is late or has gone wrong, most overdue first.
     *
     * Dated items (tasks, milestones, transfers) sort by days late; materialised
     * risks carry no due date and follow them, newest first. Every row is
     * rendered once and the queue's filter only hides rows in the browser, so
     * `allRank` and `groupRank` say where a row sits in each view. The "All" view
     * keeps up to two rows for risks, so a busy week of late work never pushes
     * a materialised risk off the dashboard.
     *
     * @return Collection<int, array{key: string, group: string, icon: string, tone: string, title: string, context: string, badge: string, daysLate: int|null, href: string, allRank: int|null, groupRank: int}>
     */
    #[Computed]
    public function attentionItems(): Collection
    {
        $today = CarbonImmutable::today();

        $tasks = Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'due_date', 'status'])
            ->with(['milestone:id,project_id', 'milestone.project:id,name', 'proposal:id,name'])
            ->where('status', '!=', TaskStatus::Done)
            ->whereDate('due_date', '<', $today)
            ->oldest('due_date')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (Task $task): array => $this->attentionEntry(
                key: 'task-'.$task->id,
                group: 'delivery',
                icon: 'clipboard-document-check',
                title: $task->title,
                context: __('dashboard.task_in', ['parent' => $task->milestone?->project?->name ?? $task->proposal?->name ?? '—']),
                daysLate: (int) $task->due_date->diffInDays($today),
                href: route('tasks.show', $task),
            ));

        $milestones = Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_date', 'status'])
            ->with('project:id,name')
            ->where('status', MilestoneStatus::Pending)
            ->whereDate('planned_date', '<', $today)
            ->oldest('planned_date')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (Milestone $milestone): array => $this->attentionEntry(
                key: 'milestone-'.$milestone->id,
                group: 'delivery',
                icon: 'flag',
                title: $milestone->name,
                context: __('dashboard.milestone_in', ['project' => $milestone->project?->name ?? '—']),
                daysLate: (int) $milestone->planned_date->diffInDays($today),
                href: route('milestones.index', ['project' => $milestone->project_id, 'status' => MilestoneStatus::Pending->value]),
            ));

        $transfers = TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'ar_status'])
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name,status'])
            ->where('ar_status', ArStatus::Pending)
            ->whereDate('scheduled_date', '<', $today)
            ->oldest('scheduled_date')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => $this->attentionEntry(
                key: 'transfer-'.$transfer->id,
                group: 'funding',
                icon: 'banknotes',
                title: __('dashboard.transfer_from', [
                    'amount' => Number::format((float) $transfer->payment_amount, maxPrecision: 0),
                    'currency' => $transfer->amount_transfered_currency,
                    'donor' => $transfer->donorProposal?->donor?->name ?? '—',
                ]),
                context: $transfer->donorProposal?->proposal?->name ?? '—',
                daysLate: (int) $transfer->scheduled_date->diffInDays($today),
                href: route('funding-agreements.show', $transfer->donor_proposal_id),
            ));

        $risks = Risk::query()
            ->select(['id', 'project_id', 'name', 'updated_at'])
            ->with('project:id,name')
            ->where('happened', true)
            ->latest('updated_at')
            ->limit(self::AttentionLimit)
            ->get()
            ->map(fn (Risk $risk): array => $this->attentionEntry(
                key: 'risk-'.$risk->id,
                group: 'risks',
                icon: 'exclamation-triangle',
                title: $risk->name,
                context: __('dashboard.risk_in', ['project' => $risk->project?->name ?? '—']),
                daysLate: null,
                href: route('risks.index', ['project' => $risk->project_id, 'status' => 'happened']),
            ));

        $dated = $tasks->concat($milestones)->concat($transfers)->sortByDesc('daysLate')->values();
        $datedShownInAll = min($dated->count(), self::AttentionLimit - min($risks->count(), self::RiskSlots));
        $risksShownInAll = min($risks->count(), self::AttentionLimit - $datedShownInAll);
        $groupRanks = [];

        return $dated
            ->map(fn (array $item, int $index): array => [...$item, 'allRank' => $index < $datedShownInAll ? $index : null])
            ->concat($risks->map(fn (array $item, int $index): array => [...$item, 'allRank' => $index < $risksShownInAll ? $datedShownInAll + $index : null]))
            ->map(function (array $item) use (&$groupRanks): array {
                $groupRanks[$item['group']] = ($groupRanks[$item['group']] ?? -1) + 1;

                return [...$item, 'groupRank' => $groupRanks[$item['group']]];
            })
            ->values();
    }

    /**
     * How many rows each view of the attention queue actually shows.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function attentionShown(): array
    {
        $items = $this->attentionItems;

        return [
            'all' => $items->whereNotNull('allRank')->count(),
            'delivery' => min(self::AttentionLimit, $items->where('group', 'delivery')->count()),
            'funding' => min(self::AttentionLimit, $items->where('group', 'funding')->count()),
            'risks' => min(self::AttentionLimit, $items->where('group', 'risks')->count()),
        ];
    }

    /**
     * How many attention items each group holds, for the queue's filter.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function attentionCounts(): array
    {
        $today = CarbonImmutable::today();

        $delivery = Task::query()->where('status', '!=', TaskStatus::Done)->whereDate('due_date', '<', $today)->count()
            + Milestone::query()->where('status', MilestoneStatus::Pending)->whereDate('planned_date', '<', $today)->count();
        $funding = TransfersTracking::query()->where('ar_status', ArStatus::Pending)->whereDate('scheduled_date', '<', $today)->count();
        $risks = Risk::query()->where('happened', true)->count();

        return [
            'all' => $delivery + $funding + $risks,
            'delivery' => $delivery,
            'funding' => $funding,
            'risks' => $risks,
        ];
    }

    /**
     * Money committed in funding agreements against money received, per currency.
     *
     * USD, EUR and SYP are not convertible in this system, so each currency is
     * its own line and nothing is ever summed across them.
     *
     * @return Collection<int, array{currency: string, committed: float, received: float, pending: float, pendingCount: int, share: int|null}>
     */
    #[Computed]
    public function fundingPosition(): Collection
    {
        $committed = DonorProposal::query()
            ->selectRaw('currency, sum(budget) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $received = TransfersTracking::query()
            ->where('ar_status', ArStatus::Received)
            ->selectRaw('amount_received_currency as currency, sum(net_amount_received) as total')
            ->groupBy('amount_received_currency')
            ->pluck('total', 'currency');

        $pending = TransfersTracking::query()
            ->where('ar_status', ArStatus::Pending)
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total, count(*) as transfers')
            ->groupBy('amount_transfered_currency')
            ->get()
            ->keyBy('currency');

        return $committed->keys()
            ->concat($received->keys())
            ->concat($pending->keys())
            ->map(fn ($currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $received, $pending): array {
                $committedTotal = (float) ($committed[$currency] ?? 0);
                $receivedTotal = (float) ($received[$currency] ?? 0);

                return [
                    'currency' => $currency,
                    'committed' => $committedTotal,
                    'received' => $receivedTotal,
                    'pending' => (float) ($pending[$currency]->total ?? 0),
                    'pendingCount' => (int) ($pending[$currency]->transfers ?? 0),
                    // Not capped: money received beyond the commitment must show as over 100%.
                    'share' => $committedTotal > 0 ? (int) floor($receivedTotal / $committedTotal * 100) : null,
                ];
            });
    }

    /**
     * The four portfolio pulse figures.
     *
     * @return array<int, array{key: string, label: string, value: int, denominator: int|null, tone: string, href: string}>
     */
    #[Computed]
    public function pulse(): array
    {
        $materialisedRisks = $this->attentionCounts['risks'];
        // Counted exactly as the reports page filters ?seenByMeal=unseen, so the
        // figure matches the list it links to.
        $awaitingReview = Report::query()->where('is_seen_by_meal', false)->count();

        return [
            [
                'key' => 'active-projects',
                'label' => __('dashboard.active_projects'),
                'value' => Project::query()->where('status', ProjectStatus::Active)->count(),
                'denominator' => Project::query()->count(),
                'tone' => 'neutral',
                'href' => route('projects.index', ['status' => ProjectStatus::Active->value]),
            ],
            [
                'key' => 'open-proposals',
                'label' => __('dashboard.proposals_in_pipeline'),
                'value' => Proposal::query()->whereNotIn('status', [ProposalStatus::Completed, ProposalStatus::Rejected])->count(),
                'denominator' => null,
                'tone' => 'funding',
                'href' => route('proposals.index'),
            ],
            [
                'key' => 'materialised-risks',
                'label' => __('dashboard.materialised_risks'),
                'value' => $materialisedRisks,
                'denominator' => null,
                'tone' => $materialisedRisks > 0 ? 'critical' : 'neutral',
                'href' => route('risks.index', ['status' => 'happened']),
            ],
            [
                'key' => 'awaiting-review',
                'label' => __('dashboard.reports_awaiting_meal_review'),
                'value' => $awaitingReview,
                'denominator' => null,
                'tone' => $awaitingReview > 0 ? 'warn' : 'neutral',
                'href' => route('reports.index', ['seenByMeal' => 'unseen']),
            ],
        ];
    }

    /**
     * The signed-in person's own workload.
     *
     * Tasks are assigned to HR employees, so a user with no linked employee has
     * no task figures, only their own draft reports.
     *
     * @return array{employeeId: int|null, overdue: int, dueThisWeek: int, draftReports: int}
     */
    #[Computed]
    public function myWork(): array
    {
        $user = auth()->user();
        $employeeId = $user->employee_id;
        $today = CarbonImmutable::today();
        $openTasks = fn () => Task::query()->where('assigned_to', $employeeId)->where('status', '!=', TaskStatus::Done);

        return [
            'employeeId' => $employeeId,
            'overdue' => $employeeId === null ? 0 : $openTasks()->whereDate('due_date', '<', $today)->count(),
            'dueThisWeek' => $employeeId === null ? 0 : $openTasks()->whereBetween('due_date', [$today->toDateString(), $today->addDays(7)->toDateString()])->count(),
            'draftReports' => Report::query()->where('user_id', $user->id)->where('status', ReportStatus::Draft)->count(),
        ];
    }

    /**
     * Projects counted by status, in lifecycle order.
     *
     * @return array{series: array<int, int>, categories: array<int, string>, tones: array<int, string>}
     */
    #[Computed]
    public function projectStatusChart(): array
    {
        $counts = Project::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $tones = [
            ProjectStatus::Draft->value => 'muted',
            ProjectStatus::Submitted->value => 'neutral',
            ProjectStatus::Approved->value => 'funding',
            ProjectStatus::Active->value => 'good',
            ProjectStatus::Closed->value => 'strong',
            ProjectStatus::Archived->value => 'warn',
        ];

        return [
            'series' => array_map(fn (ProjectStatus $status): int => (int) ($counts[$status->value] ?? 0), ProjectStatus::cases()),
            'categories' => array_map(fn (ProjectStatus $status): string => $status->label(), ProjectStatus::cases()),
            'tones' => array_values($tones),
        ];
    }

    /**
     * Proposals counted by phase of work.
     *
     * @return array{series: array<int, array{name: string, data: array<int, int>}>, categories: array<int, string>}
     */
    #[Computed]
    public function pipelineChart(): array
    {
        $byStatus = Proposal::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        // Only open proposals, matching the "Proposals in pipeline" figure: a
        // rejected or completed proposal is no longer in the pipeline, which also
        // leaves the Archived phase (Completed only) with nothing to count.
        $closed = [ProposalStatus::Rejected, ProposalStatus::Completed];
        $openStatuses = fn (ProposalPhase $phase): array => array_values(array_filter($phase->statuses(), fn (ProposalStatus $status): bool => ! in_array($status, $closed, true)));
        $phases = array_values(array_filter(ProposalPhase::cases(), fn (ProposalPhase $phase): bool => $openStatuses($phase) !== []));

        return [
            'series' => [[
                'name' => __('dashboard.proposals'),
                'data' => array_map(fn (ProposalPhase $phase): int => (int) collect($openStatuses($phase))->sum(fn (ProposalStatus $status): int => (int) ($byStatus[$status->value] ?? 0)), $phases),
            ]],
            'categories' => array_map(fn (ProposalPhase $phase): string => $phase->label(), $phases),
        ];
    }

    /**
     * Net money received per month over the last twelve months, one series per currency.
     *
     * @return array{categories: array<int, string>, currencies: array<int, array{currency: string, data: array<int, float>, total: float}>}
     */
    #[Computed]
    public function receiptsChart(): array
    {
        $start = CarbonImmutable::today()->startOfMonth()->subMonths(11);
        $months = collect(range(0, 11))->map(fn (int $offset): CarbonImmutable => $start->addMonths($offset));

        $rows = TransfersTracking::query()
            ->select(['receive_date', 'amount_received_currency', 'net_amount_received'])
            ->where('ar_status', ArStatus::Received)
            ->whereBetween('receive_date', [$start->toDateString(), CarbonImmutable::today()->toDateString()])
            ->get();

        $currencies = $rows
            ->groupBy('amount_received_currency')
            ->sortKeys()
            ->map(function (Collection $currencyRows, string $currency) use ($months): array {
                $perMonth = $currencyRows->groupBy(fn (TransfersTracking $row): string => $row->receive_date->format('Y-m'));
                $data = $months->map(fn (CarbonImmutable $month): float => round((float) ($perMonth->get($month->format('Y-m'))?->sum('net_amount_received') ?? 0), 2))->all();

                return ['currency' => $currency, 'data' => $data, 'total' => array_sum($data)];
            })
            ->values()
            ->all();

        return [
            'categories' => $months->map(fn (CarbonImmutable $month): string => $month->translatedFormat(__('formats.month_short')))->all(),
            'currencies' => $currencies,
        ];
    }

    /**
     * What falls due in the next thirty days, soonest first.
     *
     * @return Collection<int, array{key: string, date: CarbonImmutable, icon: string, tone: string, title: string, context: string, href: string}>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $today = CarbonImmutable::today();
        $horizon = [$today->toDateString(), $today->addDays(30)->toDateString()];

        $milestones = Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_date'])
            ->with('project:id,name')
            ->where('status', MilestoneStatus::Pending)
            ->whereBetween('planned_date', $horizon)
            ->oldest('planned_date')
            ->limit(8)
            ->get()
            ->map(fn (Milestone $milestone): array => [
                'key' => 'milestone-'.$milestone->id,
                'date' => $milestone->planned_date,
                'icon' => 'flag',
                'tone' => 'neutral',
                'title' => $milestone->name,
                'context' => __('dashboard.milestone_in', ['project' => $milestone->project?->name ?? '—']),
                'href' => route('milestones.index', ['project' => $milestone->project_id]),
            ]);

        $transfers = TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date'])
            ->with(['donorProposal:id,donor_id', 'donorProposal.donor:id,name'])
            ->where('ar_status', ArStatus::Pending)
            ->whereBetween('scheduled_date', $horizon)
            ->oldest('scheduled_date')
            ->limit(8)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'key' => 'transfer-'.$transfer->id,
                'date' => $transfer->scheduled_date,
                'icon' => 'banknotes',
                'tone' => 'funding',
                'title' => __('dashboard.transfer_from', [
                    'amount' => Number::format((float) $transfer->payment_amount, maxPrecision: 0),
                    'currency' => $transfer->amount_transfered_currency,
                    'donor' => $transfer->donorProposal?->donor?->name ?? '—',
                ]),
                'context' => __('dashboard.scheduled_transfer'),
                'href' => route('funding-agreements.show', $transfer->donor_proposal_id),
            ]);

        $tasks = Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'due_date'])
            ->with(['milestone:id,project_id', 'milestone.project:id,name', 'proposal:id,name'])
            ->where('status', '!=', TaskStatus::Done)
            ->whereBetween('due_date', $horizon)
            ->oldest('due_date')
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => [
                'key' => 'task-'.$task->id,
                'date' => $task->due_date,
                'icon' => 'clipboard-document-check',
                'tone' => 'neutral',
                'title' => $task->title,
                'context' => __('dashboard.task_in', ['parent' => $task->milestone?->project?->name ?? $task->proposal?->name ?? '—']),
                'href' => route('tasks.show', $task),
            ]);

        $reports = Report::query()
            ->select(['id', 'plan_indicator_id', 'planned_date', 'status'])
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->where('status', ReportStatus::Draft)
            ->whereBetween('planned_date', $horizon)
            ->oldest('planned_date')
            ->limit(8)
            ->get()
            ->map(fn (Report $report): array => [
                'key' => 'report-'.$report->id,
                'date' => $report->planned_date,
                'icon' => 'document-text',
                'tone' => 'warn',
                'title' => $report->planIndicator?->indicator?->name ?? __('dashboard.report'),
                'context' => __('dashboard.report_due'),
                'href' => route('reports.show', $report),
            ]);

        return $milestones
            ->concat($transfers)
            ->concat($tasks)
            ->concat($reports)
            ->sortBy(fn (array $item): string => $item['date']->toDateString())
            ->take(8)
            ->values();
    }

    /**
     * The latest changes across proposals, tasks and reports.
     *
     * @return Collection<int, array{key: string, at: CarbonImmutable, dateOnly: bool, icon: string, sentence: string, href: string}>
     */
    #[Computed]
    public function activity(): Collection
    {
        $notes = ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'date'])
            ->with('proposal:id,name')
            ->latest('date')
            ->limit(6)
            ->get()
            ->map(fn (ProposalStatusNote $note): array => [
                'key' => 'note-'.$note->id,
                'at' => $note->date,
                'dateOnly' => true,
                'icon' => 'arrows-right-left',
                'sentence' => __('dashboard.proposal_moved', ['proposal' => $note->proposal?->name ?? '—', 'status' => $note->status->label()]),
                'href' => route('proposals.show', $note->proposal_id),
            ]);

        $updates = TaskUpdate::query()
            ->select(['id', 'task_id', 'user_id', 'date'])
            ->with(['task:id,title', 'user:id,name'])
            ->latest('date')
            ->limit(6)
            ->get()
            ->map(fn (TaskUpdate $update): array => [
                'key' => 'update-'.$update->id,
                'at' => $update->date,
                'dateOnly' => false,
                'icon' => 'chat-bubble-left-ellipsis',
                'sentence' => __('dashboard.task_updated', ['user' => $update->user?->name ?? '—', 'task' => $update->task?->title ?? '—']),
                'href' => route('tasks.show', $update->task_id),
            ]);

        $reports = Report::query()
            ->select(['id', 'plan_indicator_id', 'user_id', 'submit_date'])
            ->with(['user:id,name', 'planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->whereNotNull('submit_date')
            ->latest('submit_date')
            ->limit(6)
            ->get()
            ->map(fn (Report $report): array => [
                'key' => 'report-'.$report->id,
                'at' => $report->submit_date,
                'dateOnly' => true,
                'icon' => 'document-check',
                'sentence' => __('dashboard.report_submitted', ['user' => $report->user?->name ?? '—', 'indicator' => $report->planIndicator?->indicator?->name ?? '—']),
                'href' => route('reports.show', $report),
            ]);

        return $notes
            ->concat($updates)
            ->concat($reports)
            ->sortByDesc(fn (array $item): int => $item['at']->getTimestamp())
            ->take(7)
            ->values();
    }

    /**
     * The greeting for the current time of day.
     */
    public function greeting(): string
    {
        $hour = (int) now()->format('G');
        $key = match (true) {
            $hour < 12 => 'dashboard.good_morning',
            $hour < 18 => 'dashboard.good_afternoon',
            default => 'dashboard.good_evening',
        };

        return __($key, ['name' => str(auth()->user()->name)->before(' ')->toString()]);
    }

    /**
     * Shape one attention row.
     *
     * @return array{key: string, group: string, icon: string, tone: string, title: string, context: string, badge: string, daysLate: int|null, href: string}
     */
    private function attentionEntry(string $key, string $group, string $icon, string $title, string $context, ?int $daysLate, string $href): array
    {
        return [
            'key' => $key,
            'group' => $group,
            'icon' => $icon,
            'tone' => match ($group) {
                'funding' => 'funding',
                'risks' => 'critical',
                default => 'warn',
            },
            'title' => $title,
            'context' => $context,
            'badge' => $daysLate === null
                ? __('common.materialised')
                : trans_choice('dashboard.days_late', max(1, $daysLate), ['count' => max(1, $daysLate)]),
            'daysLate' => $daysLate,
            'href' => $href,
        ];
    }
};
?>

@php
    $counts = $this->attentionCounts;
    $shown = $this->attentionShown;
    $myWork = $this->myWork;
    $cardClasses = 'rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none';
    $iconTone = fn (string $tone): string => match ($tone) {
        'critical' => 'bg-status-critical-soft text-primary dark:bg-primary dark:text-white',
        'warn' => 'bg-status-warn-soft text-status-warn',
        'funding' => 'bg-funding-soft text-funding',
        'good' => 'bg-status-good-soft text-status-good',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    };
    // Figures sit on the card ground: tone tokens only reach contrast there in
    // light mode, so dark mode keeps figures white and lets the icon chip carry tone.
    $figureTone = fn (string $tone): string => match ($tone) {
        'critical' => 'text-primary dark:text-white',
        'warn' => 'text-status-warn dark:text-white',
        'funding' => 'text-funding dark:text-white',
        default => 'text-secondary-dark dark:text-white',
    };
    $focusTabs = [
        'all' => __('dashboard.all'),
        'delivery' => __('dashboard.delivery'),
        'funding' => __('dashboard.funding'),
        'risks' => __('dashboard.risks'),
    ];
    $hiddenCounts = collect($focusTabs)->map(fn (string $label, string $key): int => max(0, $counts[$key] - $shown[$key]))->all();
    $receipts = $this->receiptsChart;
@endphp

<section class="@container/dashboard flex w-full flex-1 flex-col gap-5 pb-10" aria-labelledby="dashboard-heading" data-dashboard>
    {{-- Greeting and the signed-in person's own work --}}
    <header class="flex flex-col gap-4 border-b border-secondary-soft pb-7 @4xl/dashboard:flex-row @4xl/dashboard:items-center @4xl/dashboard:justify-between dark:border-secondary-medium">
        <div class="min-w-0">
            <h1 id="dashboard-heading" class="text-3xl font-bold leading-none text-balance text-secondary-dark dark:text-white">
                {{ $this->greeting() }}
            </h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-secondary-medium dark:text-secondary-soft">
                <span>{{ now()->translatedFormat(__('formats.date_long')) }}</span>
                <span aria-hidden="true" class="text-secondary-light">·</span>
                @if ($counts['all'] > 0)
                    <span class="rounded-full bg-primary px-2 py-0.5 text-xs font-bold text-white">{{ trans_choice('dashboard.items_need_attention', $counts['all'], ['count' => $counts['all']]) }}</span>
                @else
                    <span class="rounded-full bg-status-good-soft px-2 py-0.5 text-xs font-bold text-status-good">{{ __('dashboard.all_clear') }}</span>
                @endif
            </p>
        </div>

        <nav class="flex flex-wrap items-stretch gap-2" aria-label="{{ __('dashboard.my_work') }}" data-my-work>
            <span class="type-label hidden self-center pe-1 text-secondary-medium @xl/dashboard:inline dark:text-secondary-soft">{{ __('dashboard.my_work') }}</span>
            @php
                $myTasksHref = $myWork['employeeId'] ? route('tasks.index', ['assignee' => $myWork['employeeId']]) : null;
                $myWorkLinks = [
                    ['label' => __('dashboard.my_overdue_tasks'), 'value' => $myWork['overdue'], 'tone' => $myWork['overdue'] > 0 ? 'critical' : 'neutral', 'href' => $myTasksHref, 'icon' => 'clock'],
                    ['label' => __('dashboard.due_next_7_days'), 'value' => $myWork['dueThisWeek'], 'tone' => 'neutral', 'href' => $myTasksHref, 'icon' => 'calendar-days'],
                    ['label' => __('dashboard.my_draft_reports'), 'value' => $myWork['draftReports'], 'tone' => $myWork['draftReports'] > 0 ? 'warn' : 'neutral', 'href' => route('reports.index', ['status' => ReportStatus::Draft->value, 'author' => auth()->id()]), 'icon' => 'document-text'],
                ];
            @endphp
            @foreach ($myWorkLinks as $link)
                @php $tag = $link['href'] ? 'a' : 'div'; @endphp
                <{{ $tag }}
                    @if ($link['href']) href="{{ $link['href'] }}" wire:navigate @endif
                    @class([
                        $cardClasses,
                        'group flex min-h-11 items-center gap-2.5 py-1.5 ps-1.5 pe-3.5 transition-[border-color,box-shadow,transform] duration-200',
                        'hover:-translate-y-px hover:border-secondary-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary' => $link['href'],
                    ])
                    wire:key="my-work-{{ $loop->index }}"
                    data-my-work-link
                >
                    <span class="{{ $iconTone($link['tone']) }} flex size-8 items-center justify-center rounded-lg">
                        <flux:icon :name="$link['icon']" variant="micro" class="size-4" />
                    </span>
                    <span class="text-lg font-bold tabular-nums {{ $figureTone($link['tone']) }}">{{ Number::format($link['value']) }}</span>
                    <span class="type-meta max-w-28 leading-tight text-secondary-medium dark:text-secondary-soft">{{ $link['label'] }}</span>
                </{{ $tag }}>
            @endforeach
        </nav>
    </header>

    {{-- Bento: attention leads, money and pulse beside it --}}
    <div class="grid grid-cols-1 gap-4 @3xl/dashboard:grid-cols-6 @5xl/dashboard:grid-cols-12">
        {{-- Needs attention: every row renders once, the filter only hides rows in the browser --}}
        <section
            class="{{ $cardClasses }} flex min-w-0 flex-col @3xl/dashboard:col-span-6 @5xl/dashboard:col-span-7 @5xl/dashboard:row-span-2"
            aria-labelledby="attention-heading"
            x-data="{ focus: 'all' }"
            data-attention
        >
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-secondary-soft px-5 pt-4 pb-3 dark:border-secondary-medium">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h2 id="attention-heading" class="text-base font-bold text-secondary-dark dark:text-white">{{ __('dashboard.needs_attention') }}</h2>
                        <span @class([
                            'inline-flex min-w-6 items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums',
                            'bg-primary text-white' => $counts['all'] > 0,
                            'bg-status-good-soft text-status-good' => $counts['all'] === 0,
                        ])>{{ $counts['all'] }}</span>
                    </div>
                    <p class="mt-0.5 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('dashboard.needs_attention_hint') }}</p>
                </div>

                @if ($counts['all'] > 0)
                    <div class="flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-board-head p-0.5 ring-1 ring-secondary-soft dark:ring-secondary-medium" role="group" aria-label="{{ __('dashboard.filter_attention') }}">
                        @foreach ($focusTabs as $tabKey => $tabLabel)
                            <button
                                type="button"
                                x-on:click="focus = @js($tabKey)"
                                x-bind:aria-pressed="focus === @js($tabKey) ? 'true' : 'false'"
                                aria-pressed="{{ $tabKey === 'all' ? 'true' : 'false' }}"
                                wire:key="focus-{{ $tabKey }}"
                                class="inline-flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold whitespace-nowrap text-secondary-medium transition-[color,background-color,box-shadow] duration-150 hover:text-secondary-dark focus-visible:outline-2 focus-visible:outline-primary aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-[0_1px_2px_rgba(16,24,40,0.08)] dark:text-secondary-soft dark:hover:text-white dark:aria-pressed:bg-secondary-medium dark:aria-pressed:text-white"
                            >
                                {{ $tabLabel }}
                                <span class="tabular-nums opacity-70">{{ $counts[$tabKey] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex-1">
                @if ($this->attentionItems->isEmpty())
                    <x-ui.empty-state icon="check-circle" :heading="__('dashboard.nothing_needs_attention')" :body="__('dashboard.nothing_needs_attention_body')" class="min-h-80" />
                @else
                    <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium" role="list">
                        @foreach ($this->attentionItems as $item)
                            @php
                                $inAll = $item['allRank'] !== null;
                                $inGroup = $item['groupRank'] < 8;
                            @endphp
                            <li
                                wire:key="attention-{{ $item['key'] }}"
                                data-attention-group="{{ $item['group'] }}"
                                x-show="focus === 'all' ? {{ $inAll ? 'true' : 'false' }} : (focus === @js($item['group']) && {{ $inGroup ? 'true' : 'false' }})"
                                x-transition:enter="transition-opacity duration-200 ease-out"
                                x-transition:enter-start="opacity-0"
                                @unless ($inAll) style="display: none" @endunless
                            >
                                <a
                                    href="{{ $item['href'] }}"
                                    wire:navigate
                                    class="group flex items-center gap-3 px-5 py-3 transition-colors duration-150 hover:bg-board-head focus-visible:bg-board-head focus-visible:outline-none"
                                >
                                    <span class="{{ $iconTone($item['tone']) }} flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <flux:icon :name="$item['icon']" variant="mini" class="size-4.5" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $item['title'] }}</span>
                                        <span class="block truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $item['context'] }}</span>
                                    </span>
                                    <span @class([
                                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums whitespace-nowrap',
                                        'bg-status-critical-soft text-primary dark:bg-primary dark:text-white' => $item['daysLate'] === null || $item['daysLate'] > 14,
                                        'bg-white text-status-warn ring-1 ring-status-warn/40 ring-inset dark:bg-status-warn-soft dark:ring-0' => $item['daysLate'] !== null && $item['daysLate'] <= 14,
                                    ])>{{ $item['badge'] }}</span>
                                    <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-secondary-light transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @foreach ($hiddenCounts as $hiddenFocus => $hiddenCount)
                @if ($hiddenCount > 0)
                    <p
                        class="border-t border-secondary-soft px-5 py-2.5 text-xs text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft"
                        x-show="focus === @js($hiddenFocus)"
                        @unless ($hiddenFocus === 'all') style="display: none" @endunless
                        wire:key="attention-hidden-{{ $hiddenFocus }}"
                    >
                        {{ trans_choice('dashboard.more_not_shown', $hiddenCount, ['count' => $hiddenCount]) }}
                    </p>
                @endif
            @endforeach
        </section>

        {{-- Funding position --}}
        <section class="{{ $cardClasses }} flex min-w-0 flex-col @3xl/dashboard:col-span-3 @5xl/dashboard:col-span-5" aria-labelledby="funding-heading" data-funding-position>
            <div class="flex items-start justify-between gap-3 px-5 pt-4 pb-2">
                <div class="min-w-0">
                    <h2 id="funding-heading" class="text-base font-bold text-secondary-dark dark:text-white">{{ __('dashboard.funding_position') }}</h2>
                    <p class="mt-0.5 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('dashboard.funding_position_hint') }}</p>
                </div>
                <a href="{{ route('funding-agreements.index') }}" wire:navigate class="shrink-0 rounded-md text-xs font-semibold text-secondary-medium underline-offset-4 hover:text-secondary-dark hover:underline focus-visible:outline-2 focus-visible:outline-primary dark:text-secondary-soft dark:hover:text-white">
                    {{ __('dashboard.view_agreements') }}
                </a>
            </div>

            @if ($this->fundingPosition->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('dashboard.no_funding_yet')" :body="__('dashboard.no_funding_yet_body')" class="min-h-48" />
            @else
                <ul class="flex flex-col divide-y divide-secondary-soft px-5 pb-2 dark:divide-secondary-medium" role="list">
                    @foreach ($this->fundingPosition as $line)
                        <li class="py-3.5" wire:key="funding-{{ $line['currency'] }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <div class="flex min-w-0 items-baseline gap-2">
                                    <span class="rounded-md bg-funding-soft px-1.5 py-0.5 text-[11px] font-bold tracking-wide text-funding">{{ $line['currency'] }}</span>
                                    <span class="truncate text-sm text-secondary-medium dark:text-secondary-soft">
                                        <span class="font-bold text-secondary-dark tabular-nums dark:text-white">{{ Number::format($line['received'], maxPrecision: 0) }}</span>
                                        {{ __('dashboard.of_committed', ['amount' => Number::format($line['committed'], maxPrecision: 0)]) }}
                                    </span>
                                </div>
                                <span class="text-xl font-bold tabular-nums text-funding dark:text-white">{{ $line['share'] === null ? '—' : $line['share'].'%' }}</span>
                            </div>

                            <div
                                class="mt-2.5 h-2 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ min(100, $line['share'] ?? 0) }}"
                                aria-label="{{ __('dashboard.received_share', ['currency' => $line['currency']]) }}"
                            >
                                <div class="h-full rounded-full bg-funding" style="width: {{ min(100, $line['share'] ?? 0) }}%"></div>
                            </div>

                            <p class="mt-2 flex flex-wrap items-center gap-x-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($line['committed'] <= 0)
                                    <span class="font-semibold text-status-warn dark:text-white">{{ __('dashboard.no_commitment_recorded') }}</span>
                                @elseif ($line['pendingCount'] > 0)
                                    {{ trans_choice('dashboard.pending_transfers', $line['pendingCount'], ['count' => $line['pendingCount'], 'amount' => Number::format($line['pending'], maxPrecision: 0), 'currency' => $line['currency']]) }}
                                @else
                                    {{ __('dashboard.no_pending_transfers') }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Portfolio pulse --}}
        <section class="{{ $cardClasses }} grid min-w-0 grid-cols-2 @3xl/dashboard:col-span-3 @5xl/dashboard:col-span-5" aria-label="{{ __('dashboard.portfolio_pulse') }}" data-pulse>
            @foreach ($this->pulse as $figure)
                <a
                    href="{{ $figure['href'] }}"
                    wire:navigate
                    wire:key="pulse-{{ $figure['key'] }}"
                    @class([
                        'group flex flex-col justify-between gap-3 p-4 transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary @5xl/dashboard:p-5',
                        'border-e border-secondary-soft dark:border-secondary-medium' => $loop->odd,
                        'border-b border-secondary-soft dark:border-secondary-medium' => $loop->index < 2,
                        'rounded-ss-xl' => $loop->index === 0,
                        'rounded-se-xl' => $loop->index === 1,
                        'rounded-es-xl' => $loop->index === 2,
                        'rounded-ee-xl' => $loop->index === 3,
                    ])
                >
                    <span class="text-xs font-medium leading-snug text-secondary-medium dark:text-secondary-soft">{{ $figure['label'] }}</span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="type-figure {{ $figureTone($figure['tone']) }}">{{ Number::format($figure['value']) }}</span>
                        @if ($figure['denominator'] !== null)
                            <span class="text-sm text-secondary-medium tabular-nums dark:text-secondary-soft">/ {{ Number::format($figure['denominator']) }}</span>
                        @endif
                        <flux:icon.arrow-up-right variant="micro" class="ms-auto size-4 text-secondary-light opacity-0 transition-opacity duration-150 group-hover:opacity-100 rtl:-scale-x-100" />
                    </span>
                </a>
            @endforeach
        </section>
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 gap-4 @3xl/dashboard:grid-cols-2 @5xl/dashboard:grid-cols-12">
        <x-ui.panel :title="__('dashboard.projects_by_status')" :subtitle="__('dashboard.projects_by_status_hint')" class="min-w-0 @5xl/dashboard:col-span-4">
            @php $projectChart = $this->projectStatusChart; @endphp
            <div class="px-4 pt-4 pb-3">
                <x-ui.chart
                    type="donut"
                    :label="__('dashboard.projects_by_status')"
                    :series="$projectChart['series']"
                    :categories="$projectChart['categories']"
                    :tones="$projectChart['tones']"
                    :total-label="__('dashboard.projects')"
                    :height="210"
                    :empty="__('dashboard.no_projects_yet')"
                />
                <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 @md/dashboard:grid-cols-3" role="list">
                    @foreach ($projectChart['categories'] as $index => $category)
                        @php
                            $legendTone = match ($projectChart['tones'][$index]) {
                                'good' => 'bg-status-good',
                                'funding' => 'bg-funding',
                                'warn' => 'bg-status-warn',
                                'strong' => 'bg-secondary-dark dark:bg-secondary-soft',
                                'muted' => 'bg-secondary-soft dark:bg-secondary-medium',
                                default => 'bg-secondary-light',
                            };
                        @endphp
                        <li class="flex items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft" wire:key="project-legend-{{ $index }}">
                            <span class="{{ $legendTone }} size-2 shrink-0 rounded-full" aria-hidden="true"></span>
                            <span class="truncate">{{ $category }}</span>
                            <span class="ms-auto font-semibold tabular-nums text-secondary-dark dark:text-white">{{ $projectChart['series'][$index] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.panel>

        <x-ui.panel :title="__('dashboard.proposal_pipeline')" :subtitle="__('dashboard.proposal_pipeline_hint')" class="min-w-0 @5xl/dashboard:col-span-4">
            <x-slot:toolbar>
                <a href="{{ route('proposals.index') }}" wire:navigate class="rounded-md text-xs font-semibold text-secondary-medium underline-offset-4 hover:text-secondary-dark hover:underline focus-visible:outline-2 focus-visible:outline-primary dark:text-secondary-soft dark:hover:text-white">{{ __('dashboard.view_proposals') }}</a>
            </x-slot:toolbar>
            @php $pipeline = $this->pipelineChart; @endphp
            <div class="px-2 pt-3 pb-2">
                <x-ui.chart
                    type="bar"
                    horizontal
                    distributed
                    :label="__('dashboard.proposal_pipeline')"
                    :series="$pipeline['series']"
                    :categories="$pipeline['categories']"
                    :tones="['neutral', 'funding', 'good', 'warn']"
                    :height="268"
                    :empty="__('dashboard.no_proposals_yet')"
                />
            </div>
        </x-ui.panel>

        <x-ui.panel :title="__('dashboard.received_per_month')" :subtitle="__('dashboard.received_per_month_hint')" class="min-w-0 @3xl/dashboard:col-span-2 @5xl/dashboard:col-span-4">
            <div class="px-2 pt-3 pb-2" x-data="{ currency: @js($receipts['currencies'][0]['currency'] ?? null) }">
                @if (count($receipts['currencies']) === 0)
                    <x-ui.chart type="bar" :label="__('dashboard.received_per_month')" :series="[]" :categories="$receipts['categories']" :height="268" :empty="__('dashboard.no_receipts_yet')" />
                @else
                    @if (count($receipts['currencies']) > 1)
                        <div class="mb-2 flex gap-1 px-2" role="group" aria-label="{{ __('dashboard.currency') }}">
                            @foreach ($receipts['currencies'] as $receipt)
                                <button
                                    type="button"
                                    x-on:click="currency = @js($receipt['currency'])"
                                    x-bind:aria-pressed="currency === @js($receipt['currency']) ? 'true' : 'false'"
                                    x-bind:class="currency === @js($receipt['currency']) ? 'bg-funding-soft text-funding' : 'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft'"
                                    class="min-h-8 cursor-pointer rounded-md px-2.5 text-xs font-bold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary"
                                    wire:key="receipt-tab-{{ $receipt['currency'] }}"
                                >{{ $receipt['currency'] }}</button>
                            @endforeach
                        </div>
                    @endif
                    @foreach ($receipts['currencies'] as $receipt)
                        <div x-show="currency === @js($receipt['currency'])" @unless ($loop->first) style="display: none" @endunless wire:key="receipt-chart-{{ $receipt['currency'] }}">
                            <p class="px-2 text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ __('dashboard.received_last_12_months') }}
                                <span class="ms-1 text-base font-bold tabular-nums text-secondary-dark dark:text-white">{{ Number::format($receipt['total'], maxPrecision: 0) }}</span>
                                <span class="text-[11px] font-semibold">{{ $receipt['currency'] }}</span>
                            </p>
                            <x-ui.chart
                                type="bar"
                                :label="__('dashboard.received_per_month').' · '.$receipt['currency']"
                                :series="[['name' => $receipt['currency'], 'data' => $receipt['data']]]"
                                :categories="$receipts['categories']"
                                :tones="['funding']"
                                :currency="$receipt['currency']"
                                :height="count($receipts['currencies']) > 1 ? 206 : 244"
                                :empty="__('dashboard.no_receipts_yet')"
                            />
                        </div>
                    @endforeach
                @endif
            </div>
        </x-ui.panel>
    </div>

    {{-- Next 30 days and recent activity --}}
    <div class="grid grid-cols-1 gap-4 @5xl/dashboard:grid-cols-12">
        <x-ui.panel :title="__('dashboard.coming_up')" :subtitle="__('dashboard.coming_up_hint')" class="min-w-0 @5xl/dashboard:col-span-7" data-upcoming>
            @if ($this->upcoming->isEmpty())
                <x-ui.empty-state icon="calendar-days" :heading="__('dashboard.nothing_scheduled')" :body="__('dashboard.nothing_scheduled_body')" class="min-h-56" />
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium" role="list">
                    @foreach ($this->upcoming as $item)
                        @php $daysAway = (int) CarbonImmutable::today()->diffInDays($item['date']); @endphp
                        <li wire:key="upcoming-{{ $item['key'] }}">
                            <a href="{{ $item['href'] }}" wire:navigate class="group flex items-center gap-4 px-4 py-2.5 transition-colors duration-150 hover:bg-board-head focus-visible:bg-board-head focus-visible:outline-none">
                                <span class="flex w-11 shrink-0 flex-col items-center rounded-lg border border-secondary-soft py-1 leading-none dark:border-secondary-medium">
                                    <span class="text-[10px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ $item['date']->translatedFormat(__('formats.month_short')) }}</span>
                                    <span class="mt-0.5 text-base font-bold tabular-nums text-secondary-dark dark:text-white">{{ $item['date']->format('j') }}</span>
                                </span>
                                <span class="{{ $iconTone($item['tone']) }} flex size-7 shrink-0 items-center justify-center rounded-md">
                                    <flux:icon :name="$item['icon']" variant="micro" class="size-3.5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $item['title'] }}</span>
                                    <span class="block truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $item['context'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    {{ $daysAway === 0 ? __('dashboard.today') : trans_choice('dashboard.in_days', $daysAway, ['count' => $daysAway]) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('dashboard.recent_activity')" :subtitle="__('dashboard.recent_activity_hint')" class="min-w-0 @5xl/dashboard:col-span-5" data-activity>
            @if ($this->activity->isEmpty())
                <x-ui.empty-state icon="bolt" :heading="__('dashboard.no_activity_yet')" :body="__('dashboard.no_activity_yet_body')" class="min-h-56" />
            @else
                <ol class="relative px-4 py-3" role="list">
                    @foreach ($this->activity as $event)
                        <li class="relative flex gap-3 pb-3 last:pb-0" wire:key="activity-{{ $event['key'] }}">
                            @unless ($loop->last)
                                <span class="absolute start-3.5 top-8 bottom-0 w-px bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                            @endunless
                            <span class="relative flex size-7 shrink-0 items-center justify-center rounded-full bg-board-head text-secondary-medium ring-1 ring-secondary-soft dark:text-secondary-soft dark:ring-secondary-medium">
                                <flux:icon :name="$event['icon']" variant="micro" class="size-3.5" />
                            </span>
                            <a href="{{ $event['href'] }}" wire:navigate class="min-w-0 flex-1 rounded-md pt-0.5 focus-visible:outline-2 focus-visible:outline-primary">
                                <span class="line-clamp-2 text-sm text-secondary-dark hover:underline hover:underline-offset-4 dark:text-white">{{ $event['sentence'] }}</span>
                                <time class="text-xs text-secondary-medium dark:text-secondary-soft" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['dateOnly'] ? $event['at']->translatedFormat(__('formats.date_short')) : $event['at']->diffForHumans() }}</time>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    </div>
</section>
