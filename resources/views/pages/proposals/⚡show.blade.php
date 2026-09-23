<?php

use App\Enums\ArStatus;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('titles.proposal')] class extends Component
{
    use ManagesAgreementForm;
    use ManagesTransferForm;
    use WithFileUploads;

    public int $proposalId;

    /** The tabs this page offers, in strip order. */
    private const TABS = ['status', 'deadlines', 'funding', 'projects', 'tasks', 'targets', 'reports', 'documents'];

    /** How many days ahead a deadline counts as due soon. */
    private const SOON_DAYS = 30;

    /** The file categories a funded project holds that belong to the grant's paperwork. */
    private const GRANT_PAPER = [MediaCollection::Proposal, MediaCollection::Agreement, MediaCollection::Mou];

    /**
     * Which tab's content is shown beneath the fixed header.
     *
     * Round-trips through the query string, so an unknown value from a stale
     * bookmark falls back to the first tab rather than rendering nothing.
     */
    #[Url(except: 'status')]
    public string $tab = 'status';

    public bool $showStatusModal = false;

    public string $newStatus = '';

    public string $statusContext = '';

    public string $statusDate = '';

    public bool $showDetailsModal = false;

    public string $detailsName = '';

    public string $detailsPlannedBegin = '';

    public string $detailsPlannedEnd = '';

    public string $detailsActualBegin = '';

    public string $detailsActualEnd = '';

    public string $detailsNotes = '';

    public string $projectToLink = '';

    public bool $showUnlinkModal = false;

    public ?int $unlinkingProjectId = null;

    public bool $showFileModal = false;

    public bool $confirmingFileDelete = false;

    public ?int $editingFileId = null;

    public ?int $deletingFileId = null;

    public $newFile = null;

    public string $fileCategory = '';

    public string $fileDisplayName = '';

    public bool $showPreviewModal = false;

    public ?int $previewingFileId = null;

    public bool $showAddTaskModal = false;

    public string $newTaskTitle = '';

    public string $newTaskStatus = 'todo';

    public string $newTaskPriority = 'medium';

    public string $newTaskDueDate = '';

    /** @var array<int, string> */
    public array $newTaskAssignedTo = [];

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;
        $this->updatedTab();
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'status';
        }
    }

    /**
     * The tab strip, each tab counting the records it holds.
     *
     * Counts come from the same collections the tabs render, so a badge can
     * never disagree with the list beneath it.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'status', 'label' => __('proposals.status_history'), 'count' => $this->statusNotes->count()],
            ['key' => 'deadlines', 'label' => __('proposals.deadlines'), 'count' => count($this->deadlines)],
            ['key' => 'funding', 'label' => __('common.funding'), 'count' => $this->agreements->count()],
            ['key' => 'projects', 'label' => __('common.projects'), 'count' => $this->projects->count()],
            ['key' => 'tasks', 'label' => __('common.tasks'), 'count' => $this->tasks->count()],
            ['key' => 'targets', 'label' => __('common.targets'), 'count' => $this->targets->count()],
            ['key' => 'reports', 'label' => __('proposals.donor_reports'), 'count' => $this->reports->count()],
            ['key' => 'documents', 'label' => __('proposals.documents'), 'count' => $this->proposalFiles->count() + $this->projectFiles->count()],
        ];
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes', 'updated_at'])
            ->findOrFail($this->proposalId);
    }

    /** @return Collection<int, ProposalStatusNote> */
    #[Computed]
    public function statusNotes(): Collection
    {
        return ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'context', 'date'])
            ->where('proposal_id', $this->proposalId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The funding agreements on this proposal, with every tranche behind them.
     *
     * Read through donorProposals(), never through donors()->pivot: the pivot
     * built by belongsToMany carries no casts, so its currency arrives as a raw
     * string while this relation's arrives as a Currency case.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with(['donor:id,name', 'transfers.account:id,account_name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * One agreement's money, counted by the single rule every figure on this
     * page shares.
     *
     * Received counts net amounts that landed in the agreement's own currency;
     * pending counts payments sent in it. A tranche in another currency cannot
     * be set against the budget without a rate this system does not hold, so
     * it is left out of the sums and counted in `foreignCount` instead. A
     * tranche is overdue from the day after its scheduled date, never on it.
     *
     * @return array<int, array{budget: float, received: float, pending: float, unscheduled: float, overdue: float, overdueCount: int, failedCount: int, foreignCount: int, missingNetCount: int, nextDue: CarbonImmutable|null}>
     */
    #[Computed]
    public function agreementMoney(): array
    {
        $today = CarbonImmutable::today();
        $money = [];

        foreach ($this->agreements as $agreement) {
            $currency = $agreement->currency->value;
            $budget = (float) $agreement->budget;

            $receivedTransfers = $agreement->transfers->where('ar_status', ArStatus::Received);
            $received = (float) $receivedTransfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
                ->sum('net_amount_received');

            $pendingAll = $agreement->transfers->where('ar_status', ArStatus::Pending);
            $pendingTransfers = $pendingAll
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency);

            $overdueTransfers = $pendingTransfers
                ->filter(fn (TransfersTracking $transfer): bool => $this->transferIsOverdue($transfer));

            $pending = (float) $pendingTransfers->sum('payment_amount');

            $money[$agreement->id] = [
                'budget' => $budget,
                'received' => $received,
                'pending' => $pending,
                // Every tranche sent in this currency covers part of the budget,
                // whatever became of it: fees or a missing net amount do not make
                // money "unscheduled", and failed money is counted in failedCount.
                'unscheduled' => max(0.0, $budget - (float) $agreement->transfers
                    ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
                    ->sum('payment_amount')),
                'overdue' => (float) $overdueTransfers->sum('payment_amount'),
                'overdueCount' => $overdueTransfers->count(),
                'failedCount' => $agreement->transfers->where('ar_status', ArStatus::Failed)->count(),
                'missingNetCount' => $receivedTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->isMissingNetAmount())->count(),
                'foreignCount' => $receivedTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value !== $currency)->count()
                    + $pendingAll->count() - $pendingTransfers->count(),
                'nextDue' => $pendingTransfers->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first()?->scheduled_date,
            ];
        }

        return $money;
    }

    /**
     * An unsent tranche whose scheduled date has passed, in the vocabulary
     * every funding page shares: money the donor has already sent is in
     * transit, never overdue, and due today is not yet late.
     */
    public function transferIsOverdue(TransfersTracking $transfer): bool
    {
        return $transfer->state() === 'late';
    }

    /**
     * Money position per currency.
     *
     * Grouped, never summed: USD, EUR and SYP have no conversion rate in this
     * system, so a single total would be a fabricated number. Received counts
     * the net amounts that landed in the agreement's own currency; a tranche
     * that arrived in another currency is left out rather than converted.
     *
     * @return array<string, array{committed: float, received: float, pending: float, unscheduled: float, percent: int, overdue: float, overdueCount: int, failedCount: int, nextDue: CarbonImmutable|null}>
     */
    #[Computed]
    public function moneyByCurrency(): array
    {
        $lines = [];

        foreach ($this->agreements as $agreement) {
            $currency = $agreement->currency->value;
            $money = $this->agreementMoney[$agreement->id];
            $line = $lines[$currency] ?? [
                'committed' => 0.0,
                'received' => 0.0,
                'pending' => 0.0,
                'unscheduled' => 0.0,
                'percent' => 0,
                'overdue' => 0.0,
                'overdueCount' => 0,
                'failedCount' => 0,
                'nextDue' => null,
            ];

            $line['committed'] += $money['budget'];
            $line['received'] += $money['received'];
            $line['pending'] += $money['pending'];
            $line['unscheduled'] += $money['unscheduled'];
            $line['overdue'] += $money['overdue'];
            $line['overdueCount'] += $money['overdueCount'];
            $line['failedCount'] += $money['failedCount'];

            if ($money['nextDue'] !== null && ($line['nextDue'] === null || $money['nextDue']->lt($line['nextDue']))) {
                $line['nextDue'] = $money['nextDue'];
            }

            $lines[$currency] = $line;
        }

        foreach ($lines as $currency => $line) {
            $lines[$currency]['percent'] = $line['committed'] > 0.0
                ? (int) floor($line['received'] / $line['committed'] * 100)
                : 0;
        }

        uasort($lines, fn (array $a, array $b): int => $b['committed'] <=> $a['committed']);

        return $lines;
    }

    /**
     * The projects this proposal funds.
     *
     * `reached_milestones_count` rides along as a second subquery on the same
     * statement rather than a loaded relation: the header needs the reached
     * share, not the milestone rows, and counting in SQL keeps a project list
     * at one query however many milestones hang off it.
     *
     * @return Collection<int, \App\Models\Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->proposal->projects()
            ->select(['projects.id', 'projects.name', 'projects.city_id', 'projects.program_id', 'projects.status', 'projects.start_date', 'projects.end_date'])
            ->with(['city:id,name_en,name_ar', 'program:id,name'])
            ->withCount([
                'milestones',
                'risks',
                'milestones as reached_milestones_count' => fn (Builder $query) => $query->where('status', MilestoneStatus::Reached),
            ])
            ->orderBy('projects.name')
            ->get();
    }

    /**
     * Tasks delivering this proposal, carrying the project they reach through.
     *
     * A task has one source: either this proposal directly, or a milestone
     * belonging to one of the proposal's funded projects. A task has no
     * project column, so a milestone-sourced task reaches its project
     * through its milestone, and the milestone's project is loaded to group
     * the work under the project it actually lands on. Tasks with no
     * milestone (sourced straight from the proposal) are a real state and
     * get their own group rather than being dropped.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        $fundedProjectIds = $this->projects->modelKeys();
        $milestoneIds = Milestone::query()->whereIn('project_id', $fundedProjectIds)->pluck('id');

        return Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id']]),
            ])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'proposal')->where('source_id', $this->proposalId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', $milestoneIds)))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    public function openAddTaskModal(): void
    {
        $this->resetValidation();
        $this->newTaskTitle = '';
        $this->newTaskStatus = TaskStatus::Todo->value;
        $this->newTaskPriority = TaskPriority::Medium->value;
        $this->newTaskDueDate = '';
        $this->newTaskAssignedTo = [];
        $this->showAddTaskModal = true;
    }

    public function closeAddTaskModal(): void
    {
        $this->showAddTaskModal = false;
    }

    /**
     * Raise a task straight against this proposal — the source is always the
     * proposal, never one of its funded projects' milestones: a task tied to
     * one milestone's delivery is added from the milestone's own page instead.
     */
    public function addTask(): void
    {
        $this->newTaskTitle = trim($this->newTaskTitle);

        $validated = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'newTaskStatus' => ['required', new Enum(TaskStatus::class)],
            'newTaskPriority' => ['required', new Enum(TaskPriority::class)],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskAssignedTo' => ['required', 'array', 'min:1'],
            'newTaskAssignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'newTaskTitle.required' => __('tasks.enter_a_task_title'),
            'newTaskAssignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'newTaskAssignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
        ]);

        $proposalId = $this->proposalId;
        $statusCase = TaskStatus::from($validated['newTaskStatus']);
        $employeeIds = collect($validated['newTaskAssignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($proposalId, $validated, $statusCase, $employeeIds): void {
            $task = Task::query()->create([
                'source_type' => 'proposal',
                'source_id' => $proposalId,
                'title' => $validated['newTaskTitle'],
                'status' => $statusCase,
                'priority' => $validated['newTaskPriority'],
                'due_date' => $validated['newTaskDueDate'] === '' ? null : $validated['newTaskDueDate'],
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        $this->showAddTaskModal = false;
        unset($this->tasks);

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
    }

    /**
     * Targets committed in this proposal, with the reports filed against them.
     *
     * Filtered on the morph map key 'proposal', never a class name. The source
     * is hydrated with the proposal's projects so chainIssues() can answer
     * whether a target was committed against a project this proposal does not
     * fund — without that relation it would query once per target.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        $targets = PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'proposal')
            ->where('source_id', $this->proposalId)
            ->with([
                'indicator:id,name,result_id,project_id,measure,unit',
                'indicator.result:id,statement,type,program_id',
                'project:id,name,program_id',
                'reports:id,plan_indicator_id,status,value,planned_date,period_start,period_end,submit_date',
            ])
            ->get();

        $source = $this->proposal->setRelation('projects', $this->projects);
        $targets->each(fn (PlanIndicator $target) => $target->setRelation('source', $source));

        return $targets;
    }

    /**
     * Every report filed against a target committed in this proposal — what
     * the donor is owed, newest due date first.
     *
     * Read off the targets already loaded, so the tab costs no extra query.
     * Each report carries its target so the row can name the indicator.
     *
     * @return \Illuminate\Support\Collection<int, Report>
     */
    #[Computed]
    public function reports(): \Illuminate\Support\Collection
    {
        return $this->targets
            ->flatMap(fn (PlanIndicator $target) => $target->reports->each(fn (Report $report) => $report->setRelation('planIndicator', $target)))
            ->sortBy([
                fn (Report $a, Report $b): int => $b->planned_date <=> $a->planned_date,
                fn (Report $a, Report $b): int => $b->id <=> $a->id,
            ])
            ->values();
    }

    /**
     * The reports owed to donors under this proposal's agreements, carrying
     * their agreement and donor so a row can name who is owed.
     *
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function donorReports(): Collection
    {
        return DonorReport::query()
            ->select(['id', 'donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])
            ->whereIn('donor_proposal_id', $this->agreements->modelKeys())
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->each(fn (DonorReport $report) => $report->setRelation('donorProposal', $this->agreements->find($report->donor_proposal_id)));
    }

    /**
     * Whether a report is still owed: drafted but never submitted for review.
     */
    public function reportIsOwed(Report $report): bool
    {
        return $report->status === ReportStatus::Draft && $report->submit_date === null;
    }

    /**
     * Files filed on the proposal itself.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function proposalFiles(): Collection
    {
        return Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'disk', 'path', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
            ->where('mediable_type', 'proposal')
            ->where('mediable_id', $this->proposalId)
            ->with('uploader:id,name')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The grant paperwork — proposal, agreement, MOU — already filed on the
     * projects this proposal funds. Shown read-only: each file belongs to its
     * project and is managed there.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function projectFiles(): Collection
    {
        $projectIds = $this->projects->modelKeys();

        if ($projectIds === []) {
            return new Collection;
        }

        $projects = $this->projects->keyBy('id');

        return Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'disk', 'path', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
            ->where('mediable_type', 'project')
            ->whereIn('mediable_id', $projectIds)
            ->whereIn('collection', array_map(fn (MediaCollection $collection): string => $collection->value, self::GRANT_PAPER))
            ->with('uploader:id,name')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Media $media) => $media->setRelation('mediable', $projects->get($media->mediable_id)));
    }

    /**
     * Everything this grant still owes or is owed, on one dated list.
     *
     * Pending tranches, reports not yet submitted, open tasks, project ends
     * and the grant's own end. Finished items are left out — a deadline that
     * has been met is history, and the status and funding tabs hold it.
     * Undated pending tranches have no deadline and are counted elsewhere.
     *
     * @return list<array{key: string, kind: string, date: CarbonImmutable, title: string, detail: string, state: string, days: int, url: string|null, tab: string}>
     */
    #[Computed]
    public function deadlines(): array
    {
        $today = CarbonImmutable::today();
        $items = [];

        $push = function (string $key, string $kind, CarbonImmutable $date, string $title, string $detail, ?string $url, string $tab) use (&$items, $today): void {
            $days = (int) $today->diffInDays($date, false);

            $items[] = [
                'key' => $key,
                'kind' => $kind,
                'date' => $date,
                'title' => $title,
                'detail' => $detail,
                'state' => match (true) {
                    $days < 0 => 'overdue',
                    $days <= self::SOON_DAYS => 'soon',
                    default => 'later',
                },
                'days' => $days,
                'url' => $url,
                'tab' => $tab,
            ];
        };

        foreach ($this->agreements as $agreement) {
            foreach ($agreement->transfers as $transfer) {
                // Only money the donor has still to send is a deadline; a sent
                // tranche is in transit and waits on the bank, not the donor.
                if (! in_array($transfer->state(), ['late', 'upcoming'], true) || $transfer->scheduled_date === null) {
                    continue;
                }

                $push(
                    'transfer-'.$transfer->id,
                    'transfer',
                    $transfer->scheduled_date,
                    __('proposals.tranche_from_donor', ['donor' => $agreement->donor->name]),
                    number_format((float) $transfer->payment_amount, 2).' '.$transfer->amount_transfered_currency?->value,
                    route('funding-agreements.show', $agreement),
                    'funding',
                );
            }
        }

        foreach ($this->donorReports as $donorReport) {
            if ($donorReport->isSubmitted()) {
                continue;
            }

            $push(
                'donor-report-'.$donorReport->id,
                'donor-report',
                $donorReport->due_on,
                $donorReport->title,
                __('proposals.donor_report_for', ['donor' => $donorReport->donorProposal->donor->name, 'type' => $donorReport->type->label()]),
                route('funding-agreements.show', $donorReport->donor_proposal_id),
                'funding',
            );
        }

        foreach ($this->reports as $report) {
            if (! $this->reportIsOwed($report)) {
                continue;
            }

            $push(
                'report-'.$report->id,
                'report',
                $report->planned_date,
                __('proposals.report_on_indicator', ['indicator' => $report->planIndicator->indicator->name]),
                $report->planIndicator->project?->name ?? __('common.organisation_wide'),
                route('reports.show', $report->id),
                'reports',
            );
        }

        foreach ($this->tasks as $task) {
            if ($task->status === TaskStatus::Done || $task->due_date === null) {
                continue;
            }

            $push(
                'task-'.$task->id,
                'task',
                $task->due_date,
                $task->title,
                $task->status->label(),
                route('tasks.show', $task->id),
                'tasks',
            );
        }

        foreach ($this->projects as $project) {
            if ($project->end_date === null || $project->end_date->lt($today)) {
                continue;
            }

            $push(
                'project-'.$project->id,
                'project',
                $project->end_date,
                __('proposals.project_ends', ['project' => $project->name]),
                $project->program->name,
                route('projects.show', $project),
                'projects',
            );
        }

        $grantEnd = $this->proposal->actual_date_end ?? $this->proposal->planned_date_end;

        if ($grantEnd !== null && $this->proposal->actual_date_end === null && ! in_array($this->proposal->status, [ProposalStatus::Completed, ProposalStatus::Rejected], true)) {
            $push('grant-end', 'grant', $grantEnd, __('proposals.grant_term_ends'), $this->proposal->name, null, 'funding');
        }

        usort($items, fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        return $items;
    }

    /**
     * Every problem on this grant worth a manager's attention, most urgent
     * first. Each names the tab that holds its evidence.
     *
     * @return list<array{key: string, text: string, tab: string, critical: bool}>
     */
    #[Computed]
    public function warnings(): array
    {
        $warnings = [];
        $isLive = ! in_array($this->proposal->status, [ProposalStatus::Rejected, ProposalStatus::Completed], true);

        foreach ($this->moneyByCurrency as $currency => $line) {
            if ($line['overdueCount'] > 0) {
                $warnings[] = [
                    'key' => 'overdue-money-'.$currency,
                    'text' => trans_choice('proposals.warning_overdue_tranches', $line['overdueCount'], [
                        'count' => $line['overdueCount'],
                        'amount' => number_format($line['overdue'], 2),
                        'currency' => $currency,
                    ]),
                    'tab' => 'funding',
                    'critical' => true,
                ];
            }

            if ($line['failedCount'] > 0) {
                $warnings[] = [
                    'key' => 'failed-money-'.$currency,
                    'text' => trans_choice('proposals.warning_failed_transfers', $line['failedCount'], ['count' => $line['failedCount'], 'currency' => $currency]),
                    'tab' => 'funding',
                    'critical' => true,
                ];
            }

            if ($isLive && $this->term['state'] !== 'before' && $line['unscheduled'] > 0.0) {
                $warnings[] = [
                    'key' => 'unscheduled-money-'.$currency,
                    'text' => __('proposals.warning_unscheduled_money', ['amount' => number_format($line['unscheduled'], 2), 'currency' => $currency]),
                    'tab' => 'funding',
                    'critical' => false,
                ];
            }
        }

        $missingNet = collect($this->agreementMoney)->sum('missingNetCount');

        if ($missingNet > 0) {
            $warnings[] = [
                'key' => 'missing-net',
                'text' => trans_choice('funding_agreements.received_tranches_missing_net', $missingNet, ['count' => $missingNet]),
                'tab' => 'funding',
                'critical' => false,
            ];
        }

        $overdueDonorReports = collect($this->deadlines)->where('kind', 'donor-report')->where('state', 'overdue')->count();

        if ($overdueDonorReports > 0) {
            $warnings[] = [
                'key' => 'overdue-donor-reports',
                'text' => trans_choice('proposals.warning_overdue_donor_reports', $overdueDonorReports, ['count' => $overdueDonorReports]),
                'tab' => 'deadlines',
                'critical' => true,
            ];
        }

        $overdueReports = collect($this->deadlines)->where('kind', 'report')->where('state', 'overdue')->count();

        if ($overdueReports > 0) {
            $warnings[] = [
                'key' => 'overdue-reports',
                'text' => trans_choice('proposals.warning_overdue_reports', $overdueReports, ['count' => $overdueReports]),
                'tab' => 'reports',
                'critical' => true,
            ];
        }

        $overdueTasks = collect($this->deadlines)->where('kind', 'task')->where('state', 'overdue')->count();

        if ($overdueTasks > 0) {
            $warnings[] = [
                'key' => 'overdue-tasks',
                'text' => trans_choice('proposals.warning_overdue_tasks', $overdueTasks, ['count' => $overdueTasks]),
                'tab' => 'tasks',
                'critical' => true,
            ];
        }

        if ($isLive && $this->term['state'] === 'over') {
            $warnings[] = [
                'key' => 'term-over',
                'text' => trans_choice('proposals.warning_term_over', $this->term['overBy'], ['count' => $this->term['overBy']]),
                'tab' => 'status',
                'critical' => true,
            ];
        }

        $outrunning = $this->projects->filter(fn (Project $project): bool => $project->end_date !== null
            && $this->proposal->planned_date_end !== null
            && $project->end_date->gt($this->proposal->planned_date_end))->count();

        if ($outrunning > 0) {
            $warnings[] = [
                'key' => 'projects-outrun',
                'text' => trans_choice('proposals.warning_projects_outrun', $outrunning, ['count' => $outrunning]),
                'tab' => 'projects',
                'critical' => false,
            ];
        }

        $unreported = $this->targets->filter(fn (PlanIndicator $target): bool => $target->reports->isEmpty())->count();

        if ($isLive && $this->term['state'] !== 'before' && $unreported > 0) {
            $warnings[] = [
                'key' => 'unreported-targets',
                'text' => trans_choice('proposals.warning_unreported_targets', $unreported, ['count' => $unreported]),
                'tab' => 'targets',
                'critical' => false,
            ];
        }

        $brokenChains = $this->targets->filter(fn (PlanIndicator $target): bool => $target->chainIssues() !== [])->count();

        if ($brokenChains > 0) {
            $warnings[] = [
                'key' => 'broken-chains',
                'text' => trans_choice('proposals.warning_target_chain_issues', $brokenChains, ['count' => $brokenChains]),
                'tab' => 'targets',
                'critical' => false,
            ];
        }

        if ($this->agreements->isEmpty() && in_array($this->proposal->phase, [ProposalPhase::Implementation, ProposalPhase::Monitoring, ProposalPhase::Closure], true)) {
            $warnings[] = [
                'key' => 'no-agreement',
                'text' => __('proposals.warning_no_agreement'),
                'tab' => 'funding',
                'critical' => false,
            ];
        }

        usort($warnings, fn (array $a, array $b): int => $b['critical'] <=> $a['critical']);

        return $warnings;
    }

    /**
     * The four readings on the summary line, in order.
     *
     * Each carries its figure, caption, the tab holding its evidence, bar
     * segments and whether it is the one asking for attention. The money
     * reading also names every currency after the one its figure leads with.
     *
     * @return array<int, array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, others?: list<array{currency: string, text: string, alarm: bool}>, segments: array<int, array{width: float, class: string}>}>
     */
    #[Computed]
    public function vitals(): array
    {
        return [
            $this->moneyVital(),
            $this->workVital(),
            $this->timeVital(),
            $this->promisesVital(),
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function moneyVital(): array
    {
        $lines = $this->moneyByCurrency;

        if ($lines === []) {
            return [
                'key' => 'money',
                'tab' => 'funding',
                'label' => __('proposals.money_landed'),
                'figure' => '—',
                'suffix' => null,
                'caption' => __('proposals.no_funding_agreements'),
                'alarm' => false,
                'segments' => [],
            ];
        }

        $currency = array_key_first($lines);
        $line = $lines[$currency];
        $base = max($line['committed'], $line['received'] + $line['pending'], 1.0);

        // The figure leads with the largest currency; every other one is named
        // beneath it rather than left for the funding tab to reveal.
        $others = [];

        foreach (array_slice($lines, 1, null, true) as $otherCurrency => $otherLine) {
            $others[] = [
                'currency' => $otherCurrency,
                'text' => __('proposals.other_currency_landed', [
                    'percent' => DonorProposal::percentLabel($otherLine['received'], $otherLine['committed']),
                    'received' => number_format($otherLine['received'], 0),
                    'committed' => number_format($otherLine['committed'], 0),
                    'currency' => $otherCurrency,
                ]),
                'alarm' => $otherLine['overdueCount'] > 0 || $otherLine['failedCount'] > 0,
            ];
        }

        return [
            'key' => 'money',
            'tab' => 'funding',
            'label' => __('proposals.money_landed'),
            'figure' => DonorProposal::percentLabel($line['received'], $line['committed']),
            'suffix' => '%',
            'caption' => __('proposals.received_of_committed', [
                'received' => number_format($line['received'], 0),
                'committed' => number_format($line['committed'], 0),
                'currency' => $currency,
            ]),
            'alarm' => $line['overdueCount'] > 0 || $line['failedCount'] > 0,
            'others' => $others,
            'segments' => [
                ['width' => round($line['received'] / $base * 100, 2), 'class' => 'bg-funding'],
                ['width' => round($line['pending'] / $base * 100, 2), 'class' => 'money-pending'],
            ],
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function workVital(): array
    {
        $total = $this->tasks->count();
        $done = $this->tasks->where('status', TaskStatus::Done)->count();
        $projectCount = $this->projects->count();

        return [
            'key' => 'work',
            'tab' => 'tasks',
            'label' => __('proposals.work_done'),
            'figure' => (string) $done,
            'suffix' => '/'.$total,
            'caption' => $total === 0
                ? trans_choice('common.count_project', $projectCount, ['count' => $projectCount])
                : trans_choice('proposals.tasks_across_count_projects', $projectCount, ['count' => $projectCount]),
            'alarm' => $total > 0 && $done < $total && $this->term['state'] === 'over',
            'segments' => [
                ['width' => $total > 0 ? round($done / $total * 100, 2) : 0.0, 'class' => 'bg-secondary-dark dark:bg-white'],
            ],
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function timeVital(): array
    {
        $term = $this->term;

        $caption = match ($term['state']) {
            'before' => trans_choice('proposals.starts_in_count_days', $term['untilStart'], ['count' => $term['untilStart']]),
            'over' => trans_choice('proposals.count_days_past_the_planned_end', $term['overBy'], ['count' => $term['overBy']]),
            default => __('proposals.days_into_the_grant_term'),
        };

        return [
            'key' => 'time',
            'tab' => 'deadlines',
            'label' => __('proposals.time_spent'),
            'figure' => (string) $term['dayIn'],
            'suffix' => '/'.$term['days'],
            'caption' => $caption,
            'alarm' => $term['state'] === 'over' && $this->proposal->status !== ProposalStatus::Completed,
            'segments' => [
                ['width' => $term['percent'], 'class' => 'bg-secondary-medium dark:bg-secondary-soft'],
            ],
        ];
    }

    /** @return array{key: string, tab: string, label: string, figure: string, suffix: string|null, caption: string, alarm: bool, segments: array<int, array{width: float, class: string}>} */
    private function promisesVital(): array
    {
        $total = $this->targets->count();
        $reported = $this->targets->filter(fn (PlanIndicator $target): bool => $target->reports->isNotEmpty())->count();

        return [
            'key' => 'promises',
            'tab' => 'targets',
            'label' => __('proposals.promises_reported'),
            'figure' => (string) $reported,
            'suffix' => '/'.$total,
            'caption' => $total === 0
                ? __('proposals.no_targets_committed_here')
                : __('proposals.targets_carrying_at_least_one_report'),
            'alarm' => $total > 0 && $reported === 0 && $this->term['state'] !== 'before',
            'segments' => [
                ['width' => $total > 0 ? round($reported / $total * 100, 2) : 0.0, 'class' => 'bg-funding'],
            ],
        ];
    }

    /**
     * Where the grant sits in its own term.
     *
     * The clock starts at the actual begin when one was recorded and the
     * planned begin otherwise, and stops at the actual end once the work has
     * ended. A one-day term still divides by one rather than by zero.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, days: int, dayIn: int, percent: float, state: string, untilStart: int, overBy: int}
     */
    #[Computed]
    public function term(): array
    {
        $today = CarbonImmutable::today();
        $start = $this->proposal->actual_date_begin ?? $this->proposal->planned_date_begin ?? $today;
        $end = $this->proposal->actual_date_end ?? $this->proposal->planned_date_end ?? $start;

        if ($end->lt($start)) {
            $end = $start;
        }

        $days = max(1, (int) $start->diffInDays($end) + 1);
        $rawDayIn = (int) $start->diffInDays($today, false) + 1;
        $dayIn = max(0, min($days, $rawDayIn));

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'dayIn' => $dayIn,
            'percent' => round($dayIn / $days * 100, 2),
            'state' => match (true) {
                $today->lt($start) => 'before',
                $today->gt($end) => 'over',
                default => 'running',
            },
            'untilStart' => max(0, (int) $today->diffInDays($start, false)),
            'overBy' => max(0, (int) $end->diffInDays($today, false)),
        ];
    }

    /**
     * Everything dated on this proposal, placed on one scale.
     *
     * The scale spans the widest of the grant term, the projects it funds, its
     * money movements, its status moves and today, so nothing dated falls off
     * the end. Positions come back as percentages ready to be written straight
     * into inline offsets — the view does no date arithmetic of its own.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, todayPercent: float|null, termStart: float, termWidth: float, projects: array<int, array{id: int, name: string, start: float, width: float}>, money: array<int, array{key: string, at: float, date: CarbonImmutable, amount: float, currency: string, state: string}>, moves: array<int, array{at: float, date: CarbonImmutable, status: ProposalStatus}>}
     */
    #[Computed]
    public function chronology(): array
    {
        $today = CarbonImmutable::today();
        $term = $this->term;
        $dates = [$term['start'], $term['end'], $today];

        foreach ($this->projects as $project) {
            $dates[] = $project->start_date;
            $dates[] = $project->end_date;
        }

        foreach ($this->agreements as $agreement) {
            foreach ($agreement->transfers as $transfer) {
                $dates[] = $transfer->receive_date ?? $transfer->transfer_date ?? $transfer->scheduled_date;
            }
        }

        foreach ($this->statusNotes as $note) {
            $dates[] = $note->date;
        }

        $dates = collect($dates)->filter();
        $start = $dates->min();
        $end = $dates->max();
        $span = max(1, (int) $start->diffInDays($end));

        $at = fn (?CarbonImmutable $date): ?float => $date === null
            ? null
            : round(max(0, min($span, (int) $start->diffInDays($date))) / $span * 100, 3);

        $projects = [];

        foreach ($this->projects as $project) {
            $from = $at($project->start_date) ?? 0.0;
            $to = $at($project->end_date) ?? $from;

            $projects[] = [
                'id' => $project->id,
                'name' => $project->name,
                'start' => $from,
                'width' => max(2.0, $to - $from),
            ];
        }

        $money = [];

        foreach ($this->agreements as $agreement) {
            foreach ($agreement->transfers as $transfer) {
                $date = $transfer->receive_date ?? $transfer->transfer_date ?? $transfer->scheduled_date;

                if ($date === null) {
                    continue;
                }

                $isReceived = $transfer->ar_status === ArStatus::Received;

                $money[] = [
                    'key' => 'transfer-'.$transfer->id,
                    'at' => $at($date) ?? 0.0,
                    'date' => $date,
                    'amount' => (float) ($isReceived ? $transfer->net_amount_received : $transfer->payment_amount),
                    'currency' => ($isReceived ? $transfer->amount_received_currency : $transfer->amount_transfered_currency)?->value ?? '',
                    'state' => match (true) {
                        $isReceived => 'received',
                        $transfer->ar_status === ArStatus::Failed => 'failed',
                        $this->transferIsOverdue($transfer) => 'overdue',
                        default => 'pending',
                    },
                ];
            }
        }

        usort($money, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        // Gaps are sized for a phone-width scale, where one label spans about a quarter of it.
        $money = $this->laneLabels($money, 24.0);

        $moves = [];

        foreach ($this->statusNotes->sortBy('date') as $note) {
            if ($note->date === null) {
                continue;
            }

            $moves[] = ['at' => $at($note->date) ?? 0.0, 'date' => $note->date, 'status' => $note->status];
        }

        $moves = $this->laneLabels($moves, 18.0);

        $termStart = $at($term['start']) ?? 0.0;

        return [
            'start' => $start,
            'end' => $end,
            'todayPercent' => $today->betweenIncluded($start, $end) ? $at($today) : null,
            'termStart' => $termStart,
            'termWidth' => max(2.0, ($at($term['end']) ?? 100.0) - $termStart),
            'projects' => $projects,
            'money' => $money,
            'moves' => $moves,
        ];
    }

    /**
     * Give each label on a shared scale a lane and an anchor so none overlap
     * and none spill past either edge.
     *
     * Labels are placed left to right; a label starting within `$gap` percent
     * of the last one in a lane drops to the next lane. Labels near an edge
     * anchor to that edge instead of centring on their point.
     *
     * @template T of array{at: float}
     *
     * @param  list<T>  $labels
     * @return list<T&array{lane: int, anchor: string}>
     */
    private function laneLabels(array $labels, float $gap): array
    {
        $laneEnds = [];

        foreach ($labels as $index => $label) {
            $lane = 0;

            while (isset($laneEnds[$lane]) && $gap > $label['at'] - $laneEnds[$lane]) {
                $lane++;
            }

            $laneEnds[$lane] = $label['at'];
            $labels[$index]['lane'] = $lane;
            $labels[$index]['anchor'] = match (true) {
                $label['at'] < 6.0 => 'start',
                $label['at'] > 94.0 => 'end',
                default => 'center',
            };
        }

        return $labels;
    }

    /**
     * The money's climb towards what was committed, on the chronology's own
     * scale, and the payload the day scrubber reads.
     *
     * One climb per committed currency, never a single line: USD, EUR and SYP
     * have no conversion rate in this system, so one combined total would be
     * invented. The plot is as tall as whatever actually happened, so money
     * that overran its commitment is drawn rather than clipped away.
     *
     * The scrub offsets are day counts from the start of the scale and are
     * deliberately left unclamped — a project that began before the scale is
     * still running on its first day, and the readout has to say so.
     *
     * @return array{climbs: array<int, array{currency: string, committed: float, landed: float, ceiling: float, line: string, area: string}>, scrub: array<string, mixed>}
     */
    #[Computed]
    public function arrivals(): array
    {
        $chronology = $this->chronology;
        $start = $chronology['start'];
        $span = max(1, (int) $start->diffInDays($chronology['end']));
        $climbs = [];

        foreach ($this->moneyByCurrency as $currency => $line) {
            $base = max($line['committed'], $line['received'] + $line['pending'], 1.0);
            $running = 0.0;
            $points = [];

            foreach ($chronology['money'] as $event) {
                if ($event['currency'] !== $currency || $event['state'] !== 'received') {
                    continue;
                }

                $x = round($event['at'] * 10, 2);
                $points[] = $x.','.round(100 - $running / $base * 100, 2);
                $running += $event['amount'];
                $points[] = $x.','.round(100 - $running / $base * 100, 2);
            }

            $settled = round(100 - $running / $base * 100, 2);
            $polyline = $points === []
                ? '0,100 1000,100'
                : '0,100 '.implode(' ', $points).' 1000,'.$settled;

            $climbs[] = [
                'currency' => $currency,
                'committed' => $line['committed'],
                'landed' => $running,
                'ceiling' => round(100 - $line['committed'] / $base * 100, 2),
                'line' => $polyline,
                'area' => 'M'.str_replace(' ', ' L', $polyline).' L1000,100 Z',
            ];
        }

        $offset = fn (?CarbonImmutable $date): ?int => $date === null ? null : (int) $start->diffInDays($date, false);

        return [
            'climbs' => $climbs,
            'scrub' => [
                'days' => $span,
                'start' => $start->toDateString(),
                'today' => max(0, min($span, (int) $start->diffInDays(CarbonImmutable::today(), false))),
                // en-US rather than en-GB so a scrubbed date reads "Sep 21, 2026",
                // matching formats.date; Arabic keeps Latin digits so a scrubbed
                // figure matches every other tabular number on the page.
                'locale' => app()->getLocale() === 'ar' ? 'ar-u-nu-latn' : 'en-US',
                'money' => array_map(fn (array $climb): array => [
                    'currency' => $climb['currency'],
                    'committed' => round($climb['committed'], 2),
                    'transfers' => array_values(array_map(
                        fn (array $event): array => [
                            'at' => $offset($event['date']),
                            'amount' => round($event['amount'], 2),
                            'received' => $event['state'] === 'received',
                        ],
                        array_filter($chronology['money'], fn (array $event): bool => $event['currency'] === $climb['currency']),
                    )),
                ], $climbs),
                'projects' => $this->projects
                    ->map(fn ($project): array => ['from' => $offset($project->start_date), 'to' => $offset($project->end_date)])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * The lifecycle rail: seven stops, each with the date it was reached.
     *
     * A rejection answers a submission, so the rail runs to Submitted and the
     * stop after it is redrawn as the rejection rather than as the next step.
     *
     * @return array<int, array{status: ProposalStatus, label: string, date: CarbonImmutable|null, state: string}>
     */
    #[Computed]
    public function lifecycle(): array
    {
        $isRejected = $this->proposal->status === ProposalStatus::Rejected;
        $stops = ProposalStatus::lifecycle();
        $reached = (int) array_search($isRejected ? ProposalStatus::Submitted : $this->proposal->status, $stops, true);
        $dates = $this->statusNotes->sortBy('date')->groupBy(fn (ProposalStatusNote $note): string => $note->status->value);

        $rail = [];

        foreach ($stops as $index => $stop) {
            $isRejectionPoint = $isRejected && $index === $reached + 1;

            $rail[] = [
                'status' => $isRejectionPoint ? ProposalStatus::Rejected : $stop,
                'label' => $isRejectionPoint ? ProposalStatus::Rejected->label() : $stop->label(),
                'date' => $isRejectionPoint
                    ? $dates->get(ProposalStatus::Rejected->value)?->first()?->date
                    : $dates->get($stop->value)?->first()?->date,
                'state' => match (true) {
                    $isRejectionPoint => 'rejected',
                    ! $isRejected && $index === $reached => 'current',
                    $index < $reached || ($isRejected && $index === $reached) => 'past',
                    default => 'ahead',
                },
            ];
        }

        return $rail;
    }

    public function openStatusModal(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        $this->newStatus = $this->proposal->status->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStatusModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction. The live status and its audit
     * trail are the same vocabulary — proposal_status_notes.status casts to the
     * same ProposalStatus enum — so they must never be allowed to disagree.
     */
    public function changeStatus(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('proposals.choose_the_new_status'),
            'statusContext.required' => __('proposals.record_why_the_status_changed'),
            'statusDate.required' => __('proposals.set_the_date_this_change_took_effect'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->proposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $this->proposalId,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->showStatusModal = false;
        $this->statusContext = '';
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }

    /**
     * Open the details form: name, dates and notes.
     *
     * Status is deliberately absent. It moves only through changeStatus(),
     * which writes the audit note alongside it.
     */
    public function openDetailsModal(): void
    {
        Gate::authorize('update', $this->proposal);

        $proposal = $this->proposal;

        $this->resetValidation();
        $this->detailsName = $proposal->name;
        $this->detailsPlannedBegin = $proposal->planned_date_begin?->toDateString() ?? '';
        $this->detailsPlannedEnd = $proposal->planned_date_end?->toDateString() ?? '';
        $this->detailsActualBegin = $proposal->actual_date_begin?->toDateString() ?? '';
        $this->detailsActualEnd = $proposal->actual_date_end?->toDateString() ?? '';
        $this->detailsNotes = $proposal->notes ?? '';
        $this->showDetailsModal = true;
    }

    public function saveDetails(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->detailsName = trim($this->detailsName);
        $this->detailsNotes = trim($this->detailsNotes);

        $validated = $this->validate([
            'detailsName' => ['required', 'string', 'max:255'],
            'detailsPlannedBegin' => ['required', 'date'],
            'detailsPlannedEnd' => ['required', 'date', 'after_or_equal:detailsPlannedBegin'],
            'detailsActualBegin' => ['nullable', 'date'],
            'detailsActualEnd' => ['nullable', 'date', 'after_or_equal:detailsActualBegin'],
            'detailsNotes' => ['nullable', 'string'],
        ], [
            'detailsName.required' => __('proposals.enter_a_proposal_name'),
            'detailsPlannedBegin.required' => __('proposals.set_the_planned_start_date'),
            'detailsPlannedEnd.required' => __('proposals.set_the_planned_end_date'),
            'detailsPlannedEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
            'detailsActualEnd.after_or_equal' => __('proposals.the_actual_end_cannot_fall_before_the_actual_start'),
        ]);

        Proposal::query()->findOrFail($this->proposalId)->fill([
            'name' => $validated['detailsName'],
            'planned_date_begin' => $validated['detailsPlannedBegin'],
            'planned_date_end' => $validated['detailsPlannedEnd'],
            'actual_date_begin' => $validated['detailsActualBegin'] === '' ? null : $validated['detailsActualBegin'],
            'actual_date_end' => $validated['detailsActualEnd'] === '' ? null : $validated['detailsActualEnd'],
            'notes' => $validated['detailsNotes'] === '' ? null : $validated['detailsNotes'],
        ])->save();

        $this->showDetailsModal = false;
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.proposal_updated'));
    }

    /**
     * Projects not yet funded by this proposal, offered for linking.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function linkableProjects(): Collection
    {
        return Project::query()
            ->select(['id', 'name'])
            ->whereDoesntHave('proposals', fn (Builder $query) => $query->whereKey($this->proposalId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function linkProject(): void
    {
        Gate::authorize('update', $this->proposal);

        $validated = $this->validate([
            'projectToLink' => ['required', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'projectToLink.required' => __('proposals.select_a_project_to_link'),
            'projectToLink.exists' => __('proposals.that_project_is_no_longer_available'),
        ]);

        $this->proposal->projects()->syncWithoutDetaching([(int) $validated['projectToLink']]);

        $this->projectToLink = '';
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.project_linked'));
    }

    public function confirmUnlinkProject(int $projectId): void
    {
        Gate::authorize('update', $this->proposal);

        $this->unlinkingProjectId = $this->projects->firstWhere('id', $projectId)?->id;
        $this->showUnlinkModal = $this->unlinkingProjectId !== null;
    }

    /**
     * Stop funding a project. Targets already committed against it stay, and
     * the targets tab flags them, so nothing is lost silently.
     */
    public function unlinkProject(): void
    {
        Gate::authorize('update', $this->proposal);

        if ($this->unlinkingProjectId !== null) {
            $this->proposal->projects()->detach($this->unlinkingProjectId);
            Flux::toast(variant: 'success', text: __('proposals.project_unlinked'));
        }

        $this->showUnlinkModal = false;
        $this->unlinkingProjectId = null;
        $this->forgetReadings();
    }

    /**
     * A proposal file by id, scoped to this proposal so a tampered id cannot
     * reach another owner's file.
     */
    private function proposalFile(int $mediaId): Media
    {
        return Media::query()
            ->where('mediable_type', 'proposal')
            ->where('mediable_id', $this->proposalId)
            ->findOrFail($mediaId);
    }

    /**
     * A typed display name, guaranteed to carry the file's real extension so
     * the downloaded copy still opens.
     */
    private function displayNameWithExtension(string $displayName, FileType $type): string
    {
        $displayName = trim($displayName);

        if (Str::lower(pathinfo($displayName, PATHINFO_EXTENSION)) === $type->value) {
            return $displayName;
        }

        return $displayName.'.'.$type->value;
    }

    public function openCreateFileModal(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $mediaId): void
    {
        Gate::authorize('update', $this->proposal);

        $media = $this->proposalFile($mediaId);

        $this->resetValidation();
        $this->editingFileId = $media->id;
        $this->newFile = null;
        $this->fileCategory = $media->collection->value;
        $this->fileDisplayName = $media->original_name;
        $this->showFileModal = true;
    }

    /**
     * Upload a new proposal file, or rename, recategorise and optionally
     * replace an existing one — the same rules as a report's supporting files.
     *
     * The type is checked twice, by detected MIME and by extension, so a file
     * renamed to look like a PDF is refused. A replacement removes the old
     * document from disk once the row points at the new one.
     */
    public function saveFile(): void
    {
        Gate::authorize('update', $this->proposal);

        $isCreate = $this->editingFileId === null;
        $allowedTypes = implode(',', FileType::values());

        $validated = $this->validate([
            'newFile' => [$isCreate ? 'required' : 'nullable', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes],
            'fileCategory' => ['required', Rule::enum(MediaCollection::class)],
            'fileDisplayName' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'newFile.required' => __('proposals.select_a_file_to_upload'),
            'fileCategory.required' => __('proposals.select_a_file_category'),
            'fileDisplayName.required' => __('proposals.enter_a_file_name'),
        ]);

        $media = $isCreate
            ? new Media(['mediable_type' => 'proposal', 'mediable_id' => $this->proposalId, 'disk' => 'local'])
            : $this->proposalFile($this->editingFileId);

        $replaced = null;

        if ($this->newFile !== null) {
            if (! $isCreate) {
                $replaced = ['disk' => $media->disk, 'path' => $media->path];
            }

            $media->fill([
                'disk' => 'local',
                'path' => $this->newFile->store('media/proposals/'.$this->proposalId, 'local'),
                'type' => FileType::from(Str::lower($this->newFile->getClientOriginalExtension())),
                'mime_type' => $this->newFile->getMimeType(),
                'size' => $this->newFile->getSize(),
                'uploaded_at' => now(),
                'uploaded_by' => auth()->id(),
            ]);
        }

        $displayName = trim((string) ($validated['fileDisplayName'] ?? ''));

        $media->fill([
            'collection' => $validated['fileCategory'],
            'original_name' => $displayName !== ''
                ? $this->displayNameWithExtension($displayName, $media->type)
                : $this->newFile->getClientOriginalName(),
        ])->save();

        if ($replaced !== null) {
            $this->removeFromDisk($media->id, $replaced['disk'], $replaced['path']);
        }

        $this->closeFileModal();
        unset($this->proposalFiles, $this->documents, $this->tabs);

        Flux::toast(variant: 'success', text: $isCreate ? __('proposals.file_uploaded') : __('proposals.file_updated'));
    }

    public function confirmDeleteFile(int $mediaId): void
    {
        Gate::authorize('update', $this->proposal);

        $this->deletingFileId = $this->proposalFile($mediaId)->id;
        $this->confirmingFileDelete = true;
    }

    /**
     * Delete a file's row, then the stored document.
     *
     * The row goes first: a failed row delete leaves the document intact
     * behind a row that still opens it, never a row pointing at nothing.
     */
    public function deleteFile(): void
    {
        Gate::authorize('update', $this->proposal);

        if ($this->deletingFileId === null) {
            return;
        }

        $media = $this->proposalFile($this->deletingFileId);
        $media->delete();
        $this->removeFromDisk($media->id, $media->disk, $media->path);

        $this->confirmingFileDelete = false;
        $this->deletingFileId = null;
        unset($this->proposalFiles, $this->documents, $this->tabs);

        Flux::toast(variant: 'success', text: __('proposals.file_deleted'));
    }

    /**
     * Remove a stored document, logging rather than surfacing a storage
     * failure so a disk hiccup never leaves a row stuck in the UI.
     */
    private function removeFromDisk(int $mediaId, string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('Failed to delete proposal file from disk.', [
                'media_id' => $mediaId,
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Every document the tab shows, in the order it shows them: the
     * proposal's own files, then the funded projects' grant paperwork. The
     * preview steps through this same list.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function documents(): Collection
    {
        return $this->proposalFiles->concat($this->projectFiles)->values();
    }

    /**
     * Open the in-page preview for one document on this tab.
     */
    public function openPreview(int $mediaId): void
    {
        $this->previewingFileId = $this->documents->firstWhere('id', $mediaId)?->id;
        $this->showPreviewModal = $this->previewingFileId !== null;
    }

    /**
     * Step the open preview to the previous (-1) or next (1) document,
     * wrapping at either end.
     */
    public function previewAdjacent(int $direction): void
    {
        $ids = $this->documents->pluck('id')->values();
        $position = $ids->search($this->previewingFileId);

        if ($position === false || $ids->count() < 2) {
            return;
        }

        $this->previewingFileId = $ids[($position + ($direction < 0 ? -1 : 1) + $ids->count()) % $ids->count()];
    }

    public function closePreview(): void
    {
        $this->showPreviewModal = false;
        $this->previewingFileId = null;
    }

    /**
     * Where a document downloads from: its own proposal route, or the route
     * of the project it is filed on.
     */
    public function documentDownloadUrl(Media $media): string
    {
        return $media->mediable_type === 'proposal'
            ? route('proposals.files.download', [$this->proposalId, $media])
            : route('projects.files.download', [$media->mediable_id, $media]);
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->resetValidation();
    }

    protected function agreementProposalScope(): ?int
    {
        return $this->proposalId;
    }

    protected function transferProposalScope(): ?int
    {
        return $this->proposalId;
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    protected function agreementDeleted(int $agreementId): void
    {
        $this->forgetReadings();
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->forgetReadings();
    }

    /**
     * Drop every memoised reading so the next render recomputes from the
     * database. Everything on this page derives from these, so a write
     * anywhere clears them all rather than guessing which ones it touched.
     */
    private function forgetReadings(): void
    {
        unset(
            $this->proposal, $this->statusNotes, $this->agreements, $this->donorReports, $this->agreementMoney, $this->moneyByCurrency,
            $this->projects, $this->linkableProjects, $this->tasks, $this->targets, $this->reports,
            $this->projectFiles, $this->documents, $this->deadlines, $this->warnings, $this->lifecycle, $this->chronology,
            $this->arrivals, $this->vitals, $this->term, $this->tabs,
        );
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-secondary-soft';
    $inkText = 'text-secondary-dark dark:text-white';
    $hairline = 'border-secondary-soft dark:border-secondary-medium';
    $amount = fn (float $value): string => number_format($value, 2);
    $term = $this->term;
    $chronology = $this->chronology;
    $arrivals = $this->arrivals;
@endphp

<section class="dossier @container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.proposals'), 'url' => route('proposals.index')],
            ['label' => $this->proposal->name],
        ]"
        :title="$this->proposal->name"
        title-class="text-3xl! font-bold! leading-none! tracking-tight! @3xl/detail:text-[2.5rem]!"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ __('proposals.planned_range', ['begin' => $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')), 'end' => $this->proposal->planned_date_end?->translatedFormat(__('formats.date'))]) }}</span>
            @if ($this->proposal->actual_date_begin !== null)
                <span class="tabular-nums">{{ __('proposals.actual_begin', ['begin' => $this->proposal->actual_date_begin->translatedFormat(__('formats.date'))]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
                <x-ui.enum-badge :case="$this->proposal->status" />
                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $this->proposal->phase->label() }}
                </span>
                @can('update', $this->proposal)
                <x-ui.button size="sm" icon="pencil-square" class="min-h-10 touch-manipulation" wire:click="openDetailsModal" data-test="open-details-modal">{{ __('proposals.edit_details') }}</x-ui.button>
                <x-ui.button tone="primary" size="sm" icon="arrow-path" class="min-h-10 touch-manipulation" wire:click="openStatusModal" data-test="open-status-modal">{{ __('proposals.change_status') }}</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    {{-- The lifecycle as one thread: seven stops, each carrying the date it was reached.
         Where the thread scrolls (narrow screens) it opens centred on the current stop;
         the delta is measured from the rendered boxes, so it holds in RTL too. --}}
    <nav
        aria-label="{{ __('proposals.lifecycle') }}"
        class="overflow-x-auto pt-6 pb-1 [scrollbar-width:none] @3xl/detail:overflow-visible"
        x-data
        x-init="$nextTick(() => {
            const stop = $el.querySelector('[aria-current=step]');
            if (stop && $el.scrollWidth > $el.clientWidth) {
                $el.scrollLeft += stop.getBoundingClientRect().left - $el.getBoundingClientRect().left - ($el.clientWidth - stop.offsetWidth) / 2;
            }
        })"
        data-lifecycle
    >
        <ol class="grid min-w-[44rem] grid-cols-7 @3xl/detail:min-w-0">
            @foreach ($this->lifecycle as $index => $stop)
                <li
                    class="relative flex min-w-0 flex-col items-center gap-1.5"
                    wire:key="stop-{{ $index }}-{{ $stop['status']->value }}"
                    @if (in_array($stop['state'], ['current', 'rejected'], true)) aria-current="step" @endif
                >
                    @if (! $loop->first)
                        <span @class([
                            'absolute end-1/2 top-[0.3125rem] h-0.5 w-full -translate-y-1/2',
                            'bg-secondary-dark dark:bg-white' => in_array($stop['state'], ['past', 'current'], true),
                            'bg-primary' => $stop['state'] === 'rejected',
                            'bg-secondary-soft dark:bg-secondary-medium' => $stop['state'] === 'ahead',
                        ]) aria-hidden="true"></span>
                    @endif

                    <span @class([
                        'relative z-10 size-2.5 rounded-full',
                        'bg-primary ring-4 ring-primary/20' => in_array($stop['state'], ['current', 'rejected'], true),
                        'bg-secondary-dark dark:bg-white' => $stop['state'] === 'past',
                        'border-2 border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark' => $stop['state'] === 'ahead',
                    ]) aria-hidden="true"></span>

                    <span @class([
                        'px-0.5 text-center text-[11px] leading-tight',
                        'font-bold text-status-critical' => in_array($stop['state'], ['current', 'rejected'], true),
                        'font-medium '.$inkText => $stop['state'] === 'past',
                        'font-medium '.$mutedText => $stop['state'] === 'ahead',
                    ])>{{ $stop['label'] }}</span>

                    <span class="min-h-4 text-center text-[10px] tabular-nums {{ $mutedText }}">
                        @if ($stop['date'] !== null)
                            <time datetime="{{ $stop['date']->toDateString() }}">{{ $stop['date']->translatedFormat(__('formats.date_short')) }}</time>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- What needs attention now: every problem on the grant, each opening the tab that holds its evidence. --}}
    @if ($this->warnings !== [])
        <section class="mt-7 border-s-2 border-primary ps-4" aria-labelledby="warnings-heading" x-data="{ expanded: false }" data-warnings>
            <h2 id="warnings-heading" class="type-label {{ $inkText }}">{{ trans_choice('proposals.count_things_need_attention', count($this->warnings), ['count' => count($this->warnings)]) }}</h2>
            <ul class="mt-2 flex flex-col gap-1.5">
                @foreach ($this->warnings as $warning)
                    <li
                        wire:key="warning-{{ $warning['key'] }}"
                        data-warning="{{ $warning['key'] }}"
                        @if ($loop->index >= 3) x-show="expanded" x-cloak @endif
                    >
                        <button type="button" wire:click="$set('tab', '{{ $warning['tab'] }}')" aria-controls="tab-panel" class="group flex min-h-8 w-full touch-manipulation items-start gap-2.5 text-start text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-primary' => $warning['critical'], 'border-2 border-status-warn' => ! $warning['critical']]) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 underline-offset-4 group-hover:underline {{ $warning['critical'] ? 'font-semibold '.$inkText : $inkText }}">{{ $warning['text'] }}</span>
                            <flux:icon.arrow-right variant="micro" class="mt-0.5 size-4 shrink-0 {{ $mutedText }} rtl:rotate-180" aria-hidden="true" />
                        </button>
                    </li>
                @endforeach
            </ul>
            @if (count($this->warnings) > 3)
                <button type="button" class="mt-2 min-h-8 touch-manipulation text-xs font-semibold underline-offset-4 hover:underline {{ $mutedText }}" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded" data-toggle-warnings>
                    <span x-show="! expanded">{{ __('proposals.show_all_count', ['count' => count($this->warnings)]) }}</span>
                    <span x-show="expanded" x-cloak>{{ __('proposals.show_fewer') }}</span>
                </button>
            @endif
        </section>
    @endif

    {{-- The bench: four readings of this grant's condition; each opens the tab holding its evidence. --}}
    <div
        class="mt-7 grid grid-cols-2 gap-x-6 gap-y-7 @3xl/detail:grid-cols-4"
        role="group"
        aria-label="{{ __('proposals.the_grant_s_vital_signs') }}"
        data-bench
    >
        @foreach ($this->vitals as $reading)
            <button
                type="button"
                wire:click="$set('tab', '{{ $reading['tab'] }}')"
                aria-controls="tab-panel"
                class="logframe-reveal group -mx-2 flex min-w-0 touch-manipulation flex-col px-2 text-start focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                style="--logframe-order: {{ $loop->index }}"
                wire:key="vital-{{ $reading['key'] }}"
                data-vital="{{ $reading['key'] }}"
                data-test="vital-{{ $reading['key'] }}"
            >
                <span class="type-label {{ $mutedText }}">{{ $reading['label'] }}</span>

                <span @class([
                    'mt-2 flex min-w-0 items-baseline gap-0.5 text-4xl leading-none font-extrabold tracking-tight tabular-nums @xl/detail:text-5xl @5xl/detail:text-[3.5rem]',
                    'text-status-critical' => $reading['alarm'],
                    $inkText => ! $reading['alarm'],
                ])>
                    <span class="truncate">{{ $reading['figure'] }}</span>
                    @if ($reading['suffix'] !== null)
                        <span @class(['text-2xl font-bold tracking-normal @xl/detail:text-3xl', 'text-status-critical/70' => $reading['alarm'], $mutedText => ! $reading['alarm']])>{{ $reading['suffix'] }}</span>
                    @endif
                </span>

                <span class="mt-2 line-clamp-2 text-xs {{ $mutedText }}">{{ $reading['caption'] }}</span>

                @foreach ($reading['others'] ?? [] as $other)
                    <span @class([
                        'mt-1 truncate text-xs font-semibold tabular-nums',
                        'text-status-critical' => $other['alarm'],
                        $inkText => ! $other['alarm'],
                    ]) data-test="vital-money-{{ $other['currency'] }}">{{ $other['text'] }}</span>
                @endforeach

                <span class="mt-3 flex h-[3px] w-full gap-px overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true">
                    @foreach ($reading['segments'] as $segment)
                        <span class="money-grow h-full first:rounded-s-full {{ $segment['class'] }}" style="width: {{ $segment['width'] }}%"></span>
                    @endforeach
                </span>
            </button>
        @endforeach
    </div>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" class="mt-8 flex-nowrap! overflow-x-auto [scrollbar-width:none] *:shrink-0 @3xl/detail:flex-wrap!" />

    <div id="tab-panel" class="mt-6 min-w-0" wire:key="tab-{{ $tab }}" data-tab-panel="{{ $tab }}">
        @if ($tab === 'funding')
            <div class="mb-6 flex flex-wrap items-center justify-end gap-2">
                @can('transfers.create')
                <x-ui.button size="sm" icon="calendar-days" class="min-h-10 touch-manipulation" wire:click="openTransferCreate" :disabled="$this->agreements->isEmpty()" data-test="schedule-tranche">{{ __('proposals.schedule_tranche') }}</x-ui.button>
                @endcan
                @can('funding-agreements.create')
                <x-ui.button tone="primary" size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openAgreementCreate" data-test="record-agreement">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                @endcan
            </div>

            @if ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('proposals.no_funding_agreements')" :body="__('proposals.agreements_signed_against_this_proposal_will_appear_here')" />
            @else
                @if (count($this->moneyByCurrency) > 1)
                    <p class="mb-5 text-xs {{ $mutedText }}">{{ __('proposals.budgets_are_shown_per_currency_they_are_never_added_together') }}</p>
                @endif

                <div class="flex flex-col gap-8">
                    @foreach ($this->agreements as $agreement)
                        @php
                            $money = $this->agreementMoney[$agreement->id];
                            $budget = $money['budget'];
                            $currency = $agreement->currency->value;
                            $agreementReceived = $money['received'];
                            $agreementPending = $money['pending'];
                        @endphp
                        <article class="min-w-0" wire:key="agreement-{{ $agreement->id }}" data-agreement="{{ $agreement->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <p class="min-w-0 text-sm">
                                    <a href="{{ route('donors.show', $agreement->donor_id) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->donor->name }}</a>
                                    <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="ms-2 text-xs underline-offset-4 hover:underline {{ $mutedText }}">{{ filled($agreement->project_num) ? __('proposals.agreement_number', ['number' => $agreement->project_num]) : __('proposals.view_agreement') }}</a>
                                </p>
                                <div class="flex items-center gap-1">
                                    <p class="me-2 text-2xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $amount($budget) }} <span class="text-xs font-semibold tracking-normal {{ $mutedText }}">{{ $currency }}</span></p>
                                    @can('transfers.create')
                                    <x-ui.button tone="ghost" size="sm" icon="plus" class="size-9! touch-manipulation" wire:click="openTransferCreate({{ $agreement->id }})" :aria-label="__('proposals.schedule_tranche_for', ['donor' => $agreement->donor->name])" data-test="schedule-tranche-{{ $agreement->id }}" />
                                    @endcan
                                    @can('funding-agreements.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openAgreementEdit({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreement->donor->name])" data-test="edit-agreement-{{ $agreement->id }}" />
                                    @endcan
                                </div>
                            </div>

                            <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $currency, 'received' => $amount($agreementReceived), 'pending' => $amount($agreementPending), 'committed' => $amount($budget)]) }}">
                                <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $budget > 0 ? min(100, round($agreementReceived / $budget * 100, 2)) : 0 }}%"></span>
                                <span class="money-grow money-pending h-full" style="width: {{ $budget > 0 ? min(100, round($agreementPending / $budget * 100, 2)) : 0 }}%"></span>
                            </div>

                            @if ($money['foreignCount'] > 0)
                                <p class="mt-2 text-xs {{ $mutedText }}" data-foreign-tranches>{{ trans_choice('proposals.count_tranches_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount'], 'currency' => $currency]) }}</p>
                            @endif

                            @if ($agreement->transfers->isEmpty())
                                <p class="mt-3 text-sm {{ $mutedText }}">{{ __('proposals.committed_with_no_transfer_yet') }}</p>
                            @else
                                <ol class="mt-4 flex flex-col">
                                    {{-- Dated tranches in date order; undated ones have no place on the calendar yet, so they sit last. --}}
                                    @foreach ($agreement->transfers->sortBy([
                                        fn (TransfersTracking $a, TransfersTracking $b): int => (($a->receive_date ?? $a->scheduled_date) === null) <=> (($b->receive_date ?? $b->scheduled_date) === null),
                                        fn (TransfersTracking $a, TransfersTracking $b): int => ($a->receive_date ?? $a->scheduled_date) <=> ($b->receive_date ?? $b->scheduled_date),
                                        fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
                                    ]) as $transfer)
                                        @php
                                            $isReceived = $transfer->ar_status === ArStatus::Received;
                                            $isOverdue = $this->transferIsOverdue($transfer);
                                            $shownAmount = (float) ($isReceived ? $transfer->net_amount_received : $transfer->payment_amount);
                                            $shownCurrency = ($isReceived ? $transfer->amount_received_currency : $transfer->amount_transfered_currency)?->value ?? $currency;
                                        @endphp
                                        <li class="relative flex min-w-0 gap-3 pb-4 last:pb-0" wire:key="transfer-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}">
                                            <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                                @unless ($loop->last)
                                                    <span class="absolute top-3 bottom-[-1rem] w-px bg-secondary-soft dark:bg-secondary-medium"></span>
                                                @endunless
                                                <span @class([
                                                    'relative z-10 mt-1.5 size-2.5 rounded-full',
                                                    'bg-funding' => $isReceived,
                                                    'bg-primary' => ! $isReceived && ($isOverdue || $transfer->ar_status === ArStatus::Failed),
                                                    'border-2 border-status-warn bg-white dark:bg-secondary-dark' => ! $isReceived && ! $isOverdue && $transfer->ar_status !== ArStatus::Failed,
                                                ])></span>
                                            </span>

                                            <div class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold tabular-nums {{ $inkText }}">
                                                        {{ $amount($shownAmount) }} <span class="text-xs font-medium {{ $mutedText }}">{{ $shownCurrency }}</span>
                                                    </p>
                                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                                        @if ($transfer->receive_date !== null)
                                                            <span>{{ __('proposals.received_on', ['date' => $transfer->receive_date->translatedFormat(__('formats.date'))]) }}</span>
                                                        @elseif ($transfer->transfer_date !== null)
                                                            <span>{{ __('proposals.sent_on', ['date' => $transfer->transfer_date->translatedFormat(__('formats.date'))]) }}</span>
                                                        @elseif ($transfer->scheduled_date !== null)
                                                            <span @class(['font-semibold text-status-critical' => $isOverdue])>{{ __('proposals.scheduled_for', ['date' => $transfer->scheduled_date->translatedFormat(__('formats.date'))]) }}</span>
                                                        @else
                                                            <span>{{ __('common.unscheduled') }}</span>
                                                        @endif
                                                        <span aria-hidden="true">·</span>
                                                        <span>{{ $transfer->account?->account_name ?? __('proposals.no_account_recorded') }}</span>
                                                        @if ((float) $transfer->fees > 0)
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ __('proposals.fees_amount', ['amount' => $amount((float) $transfer->fees), 'currency' => $transfer->fees_currency?->value ?? $currency]) }}</span>
                                                        @endif
                                                    </p>
                                                </div>

                                                <div class="flex shrink-0 items-center gap-2">
                                                    @if ($isOverdue)
                                                        <span class="text-[11px] font-bold tracking-wide text-status-critical uppercase">{{ __('proposals.overdue') }}</span>
                                                    @elseif ($transfer->state() === 'transit')
                                                        <span class="text-[11px] font-bold tracking-wide text-status-warn uppercase" data-transit>{{ __('funding_agreements.tranche_state_transit') }}</span>
                                                    @elseif ($transfer->isMissingNetAmount())
                                                        <span class="text-[11px] font-bold tracking-wide text-status-warn uppercase" data-missing-net>{{ __('funding_agreements.received_net_not_recorded') }}</span>
                                                    @endif
                                                    <x-ui.enum-badge :case="$transfer->ar_status" />
                                                    @can('transfers.update')
                                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openTransferEdit({{ $transfer->id }})" :aria-label="__('proposals.edit_tranche')" data-test="edit-transfer-{{ $transfer->id }}" />
                                                    @endcan
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="mt-8 flex flex-col gap-5 border-t pt-5 {{ $hairline }}">
                    @foreach ($this->moneyByCurrency as $currencyCode => $line)
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 @2xl/detail:grid-cols-[3.5rem_repeat(4,minmax(0,1fr))]" wire:key="money-line-{{ $currencyCode }}">
                            <p class="col-span-2 text-sm font-extrabold tracking-wide text-funding @2xl/detail:col-span-1">{{ $currencyCode }}</p>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.received') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums text-funding">{{ $amount($line['received']) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.pending') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums text-status-warn">{{ $amount($line['pending']) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.not_yet_scheduled') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums {{ $mutedText }}">{{ $amount($line['unscheduled']) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.committed') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums {{ $inkText }}">{{ $amount($line['committed']) }}</dd>
                            </div>
                        </dl>
                    @endforeach
                </div>
            @endif

            {{-- The same money on one time scale with the term, the projects and the status moves. --}}
            <div class="mt-10 border-t pt-6 {{ $hairline }}">
                {{-- One scale: the grant term, the projects it funds, its money and its status moves. --}}
                <div class="min-w-0" data-chronology x-data="proposalClock(@js($arrivals['scrub']))">
                    <p class="text-xs {{ $mutedText }}">{{ __('proposals.one_scale_from_to', ['from' => $chronology['start']->translatedFormat(__('formats.date')), 'to' => $chronology['end']->translatedFormat(__('formats.date'))]) }}</p>

                    <div class="relative mt-6 pt-5">
                        @if ($chronology['todayPercent'] !== null)
                            <span class="pointer-events-none absolute inset-y-0 z-20 w-px bg-primary" style="inset-inline-start: {{ $chronology['todayPercent'] }}%" aria-hidden="true"></span>
                            <span class="absolute top-0 z-20 -translate-x-1/2 text-[10px] font-bold tracking-wide text-status-critical uppercase rtl:translate-x-1/2" style="inset-inline-start: {{ $chronology['todayPercent'] }}%">{{ __('common.today') }}</span>
                        @endif

                        {{-- The scrubbing cursor, drawn over every lane at once. --}}
                        <span class="chrono-cursor pointer-events-none absolute inset-y-0 z-20 w-px" style="display: none" :style="`inset-inline-start: ${percent}%`" x-show="scrubbing" aria-hidden="true"></span>

                        {{--
                            The climb: how the money actually arrived against what was
                            committed. One plot per currency, each scaled to its own
                            commitment, because the two are never added together.
                        --}}
                        @foreach ($arrivals['climbs'] as $climb)
                            <div class="relative mb-1 h-16 @2xl/detail:h-20" wire:key="climb-{{ $climb['currency'] }}" data-money-climb>
                                <svg class="chrono-plot absolute inset-0 size-full" viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                    <path d="{{ $climb['area'] }}" class="chrono-area" />
                                    <polyline points="{{ $climb['line'] }}" class="chrono-line" />
                                    <line x1="0" x2="1000" y1="{{ $climb['ceiling'] }}" y2="{{ $climb['ceiling'] }}" class="chrono-ceiling" />
                                </svg>
                                <p class="pointer-events-none absolute end-0 text-[10px] font-semibold tabular-nums {{ $mutedText }}" style="inset-block-start: max(0px, calc({{ $climb['ceiling'] }}% - 1.05rem))">
                                    {{ __('proposals.committed_ceiling', ['amount' => number_format($climb['committed'], 0), 'currency' => $climb['currency']]) }}
                                </p>
                                <p class="pointer-events-none absolute start-0 bottom-0 text-[10px] font-semibold tabular-nums text-funding">
                                    {{ __('proposals.landed_so_far', ['amount' => number_format($climb['landed'], 0), 'currency' => $climb['currency']]) }}
                                </p>
                            </div>
                        @endforeach

                        {{-- Money above the line. --}}
                        @if ($chronology['money'] !== [])
                            {{-- Labels that would collide climb into higher lanes on a stem; labels near an edge anchor to it. --}}
                            <ol class="relative" style="height: {{ 4 + collect($chronology['money'])->max('lane') * 2 }}rem" aria-label="{{ __('common.transfers') }}">
                                @foreach ($chronology['money'] as $event)
                                    <li @class([
                                        'absolute bottom-0',
                                        '-translate-x-1/2 rtl:translate-x-1/2' => $event['anchor'] === 'center',
                                        '-translate-x-full rtl:translate-x-full' => $event['anchor'] === 'end',
                                    ]) style="inset-inline-start: {{ $event['at'] }}%" wire:key="chrono-{{ $event['key'] }}" data-lane="{{ $event['lane'] }}">
                                        <span @class(['flex flex-col gap-1', 'items-center' => $event['anchor'] === 'center', 'items-start' => $event['anchor'] === 'start', 'items-end' => $event['anchor'] === 'end'])>
                                            <span @class([
                                                'text-[11px] font-bold whitespace-nowrap tabular-nums',
                                                'text-funding' => $event['state'] === 'received',
                                                'text-status-critical' => in_array($event['state'], ['overdue', 'failed'], true),
                                                'text-status-warn' => $event['state'] === 'pending',
                                            ])>{{ number_format($event['amount'], 0) }} {{ $event['currency'] }}</span>
                                            <span class="text-[10px] whitespace-nowrap tabular-nums {{ $mutedText }}">{{ $event['date']->translatedFormat(__('formats.date_short')) }}</span>
                                            @if ($event['lane'] > 0)
                                                <span class="-my-1 w-px bg-secondary-light {{ $event['anchor'] === 'start' ? 'ms-[0.3125rem]' : ($event['anchor'] === 'end' ? 'me-[0.3125rem]' : '') }}" style="height: {{ $event['lane'] * 2 }}rem" aria-hidden="true"></span>
                                            @endif
                                            <span @class([
                                                'size-2.5 shrink-0 rounded-full',
                                                'bg-funding' => $event['state'] === 'received',
                                                'bg-primary' => in_array($event['state'], ['overdue', 'failed'], true),
                                                'border-2 border-status-warn bg-white dark:bg-secondary-dark' => $event['state'] === 'pending',
                                            ]) aria-hidden="true"></span>
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        {{--
                            The spine: the grant's own term on the shared scale, and
                            the handle that reads the grant on any single day. It is
                            a slider, so the whole scale is reachable from the
                            keyboard; the page states every figure without it.
                        --}}
                        <div
                            class="group relative -my-3 cursor-ew-resize touch-none py-3 select-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                            role="slider"
                            tabindex="0"
                            aria-valuemin="0"
                            aria-valuemax="{{ $arrivals['scrub']['days'] }}"
                            :aria-valuenow="day"
                            :aria-valuetext="readout.date"
                            aria-label="{{ __('proposals.read_the_grant_on_a_day') }}"
                            x-ref="spine"
                            x-on:pointerdown="scrubStart($event)"
                            x-on:pointermove="scrubMove($event)"
                            x-on:pointerup="scrubEnd($event)"
                            x-on:pointercancel="scrubEnd($event)"
                            x-on:keydown="scrubKey($event)"
                            x-on:blur="scrubbing = false"
                        >
                            <div class="relative h-2 w-full rounded-full bg-secondary-soft dark:bg-secondary-medium">
                                <span
                                    class="absolute inset-y-0 rounded-full bg-secondary-dark dark:bg-white"
                                    style="inset-inline-start: {{ $chronology['termStart'] }}%; width: {{ $chronology['termWidth'] }}%"
                                    role="img"
                                    aria-label="{{ __('proposals.grant_term_from_to', ['from' => $term['start']->translatedFormat(__('formats.date')), 'to' => $term['end']->translatedFormat(__('formats.date'))]) }}"
                                ></span>
                                <span
                                    class="chrono-handle absolute top-1/2 size-3.5 rounded-full border-2 border-white bg-secondary-dark opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 dark:border-secondary-dark dark:bg-white"
                                    :class="scrubbing && 'opacity-100!'"
                                    :style="`inset-inline-start: ${percent}%`"
                                    aria-hidden="true"
                                ></span>
                            </div>
                        </div>

                        {{-- Work below the line. --}}
                        <ul class="mt-3 flex flex-col gap-2" aria-label="{{ __('common.projects') }}">
                            @forelse ($chronology['projects'] as $bar)
                                <li class="relative h-7" wire:key="chrono-project-{{ $bar['id'] }}">
                                    <a
                                        href="{{ route('projects.show', $bar['id']) }}"
                                        wire:navigate
                                        class="absolute top-0 flex h-7 min-w-0 items-center rounded-sm bg-funding-soft px-2 text-[11px] font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-white"
                                        style="inset-inline-start: {{ $bar['start'] }}%; width: {{ $bar['width'] }}%"
                                    >
                                        <span class="truncate">{{ $bar['name'] }}</span>
                                    </a>
                                </li>
                            @empty
                                <li class="text-xs {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</li>
                            @endforelse
                        </ul>

                        {{-- Status moves on the same scale. --}}
                        @if ($chronology['moves'] !== [])
                            <ol class="relative mt-4" style="height: {{ 2.25 + collect($chronology['moves'])->max('lane') * 1.25 }}rem" aria-label="{{ __('proposals.status_changes') }}">
                                @foreach ($chronology['moves'] as $move)
                                    <li @class([
                                        'absolute top-0',
                                        '-translate-x-1/2 rtl:translate-x-1/2' => $move['anchor'] === 'center',
                                        '-translate-x-full rtl:translate-x-full' => $move['anchor'] === 'end',
                                    ]) style="inset-inline-start: {{ $move['at'] }}%" wire:key="chrono-move-{{ $loop->index }}" data-lane="{{ $move['lane'] }}">
                                        <span @class(['flex flex-col gap-1', 'items-center' => $move['anchor'] === 'center', 'items-start' => $move['anchor'] === 'start', 'items-end' => $move['anchor'] === 'end'])>
                                            <span class="size-2 shrink-0 rounded-full bg-secondary-medium dark:bg-secondary-soft" aria-hidden="true"></span>
                                            @if ($move['lane'] > 0)
                                                <span class="-my-1 w-px bg-secondary-light {{ $move['anchor'] === 'start' ? 'ms-1' : ($move['anchor'] === 'end' ? 'me-1' : '') }}" style="height: {{ $move['lane'] * 1.25 }}rem" aria-hidden="true"></span>
                                            @endif
                                            <span class="text-[10px] font-semibold whitespace-nowrap {{ $mutedText }}">{{ $move['status']->label() }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>

                    {{--
                        The readout. It states today's position on its own, and
                        answers for whichever day the spine is scrubbed to — so the
                        question "had the money landed before that work was due?"
                        is read off the page rather than worked out from it.
                    --}}
                    @if ($arrivals['climbs'] !== [])
                        <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 border-t pt-5 {{ $hairline }} @2xl/detail:grid-cols-4" data-chrono-readout>
                            <div>
                                <dt class="type-label {{ $mutedText }}" x-text="scrubbing ? @js(__('proposals.as_of_that_day')) : @js(__('common.today'))">{{ __('common.today') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}" x-text="readout.date">{{ \Carbon\CarbonImmutable::today()->translatedFormat(__('formats.date')) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.received_by_then') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums text-funding">
                                    <span x-text="readout.received">{{ number_format($arrivals['climbs'][0]['landed'], 0) }}</span>
                                    <span class="text-[11px] font-semibold {{ $mutedText }}" x-text="currency">{{ $arrivals['climbs'][0]['currency'] }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.still_owed_then') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">
                                    <span x-text="readout.owed">{{ number_format(max(0, $arrivals['climbs'][0]['committed'] - $arrivals['climbs'][0]['landed']), 0) }}</span>
                                    <span class="text-[11px] font-semibold {{ $mutedText }}" x-text="currency">{{ $arrivals['climbs'][0]['currency'] }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.projects_running_then') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}" x-text="readout.running">{{ $this->projects->count() }}/{{ $this->projects->count() }}</dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-[11px] {{ $mutedText }}">{{ __('proposals.scrub_hint') }}</p>
                    @endif

                    <dl class="mt-8 grid grid-cols-2 gap-x-6 gap-y-4 border-t pt-5 {{ $hairline }} @2xl/detail:grid-cols-4">
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.planned_start') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.planned_end') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->planned_date_end?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.actual_start') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->actual_date_begin?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.actual_end') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->actual_date_end?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        @elseif ($tab === 'projects')
            @can('update', $this->proposal)
            <form wire:submit="linkProject" class="mb-6 flex flex-col gap-2 @xl/detail:flex-row @xl/detail:items-end" data-link-project>
                <div class="min-w-0 flex-1">
                    <x-ui.select wire:model="projectToLink" :label="__('proposals.fund_another_project')">
                        <flux:select.option value="">{{ __('proposals.select_a_project') }}</flux:select.option>
                        @foreach ($this->linkableProjects as $linkable)
                            <flux:select.option :value="$linkable->id" :wire:key="'link-project-'.$linkable->id">{{ $linkable->name }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button type="submit" tone="primary" icon="link" class="min-h-10 touch-manipulation" :disabled="$this->linkableProjects->isEmpty()" data-test="link-project">{{ __('proposals.link_project') }}</x-ui.button>
            </form>
            @endcan

            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('proposals.no_projects_linked')" :body="__('proposals.projects_this_proposal_funds_will_appear_here')" />
            @else
                <div class="flex flex-col gap-8">
                    @foreach ($this->projects as $project)
                        @php
                            $projectTasks = $this->tasks->filter(fn (\App\Models\Task $task): bool => $task->sourceProjectId() === $project->id);
                            $projectDone = $projectTasks->where('status', TaskStatus::Done)->count();
                            // Work scheduled past the grant's own end has no funding behind it after that date.
                            $runsPastTheGrant = $project->end_date !== null
                                && $this->proposal->planned_date_end !== null
                                && $project->end_date->gt($this->proposal->planned_date_end);
                        @endphp
                        <article class="relative min-w-0 ps-6" wire:key="project-{{ $project->id }}" data-project="{{ $project->id }}">
                            {{-- The thread: one mark per funded project, filled once its milestones are all reached. --}}
                            <span class="absolute start-[0.1875rem] top-5 bottom-0 w-px bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                            <span @class([
                                'absolute start-0 top-1.5 size-2.5 rounded-full',
                                'bg-secondary-dark dark:bg-white' => $project->milestones_count > 0 && $project->reached_milestones_count >= $project->milestones_count,
                                'border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark' => $project->milestones_count === 0 || $project->reached_milestones_count < $project->milestones_count,
                            ]) aria-hidden="true"></span>

                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0">
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}">{{ $project->name }}</a>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs {{ $mutedText }}">
                                        <span>{{ $project->program->name }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $project->city?->name ?? __('common.unknown_city') }}</span>
                                        @if ($project->start_date !== null || $project->end_date !== null)
                                            <span aria-hidden="true">·</span>
                                            <span class="tabular-nums">{{ __('proposals.planned_range', ['begin' => $project->start_date?->translatedFormat(__('formats.date')) ?? '—', 'end' => $project->end_date?->translatedFormat(__('formats.date')) ?? '—']) }}</span>
                                        @endif
                                        @if ($project->risks_count > 0)
                                            <span aria-hidden="true">·</span>
                                            {{-- Weight, not colour: HC red on this page means overdue, and it fails contrast on the dark ground anyway. --}}
                                            <span class="font-bold {{ $inkText }}">{{ trans_choice('common.count_risk', $project->risks_count, ['count' => $project->risks_count]) }}</span>
                                        @endif
                                    </p>
                                    {{-- A project running past the grant it is funded by is the fundraiser's problem, so it is said plainly.
                                         Tinted chip rather than bare red text: --color-primary on the dark ground reads at 1.14:1,
                                         while status-critical on its own soft field clears 4.5:1 in both themes. --}}
                                    @if ($runsPastTheGrant)
                                        <p class="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-status-critical" data-outruns-grant>
                                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('proposals.runs_past_the_grant_term') }}
                                        </p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <x-ui.enum-badge :case="$project->status" />
                                    @can('update', $this->proposal)
                                    <x-ui.button tone="ghost" size="sm" icon="link-slash" class="size-9! touch-manipulation" wire:click="confirmUnlinkProject({{ $project->id }})" :aria-label="__('proposals.unlink_project_name', ['name' => $project->name])" data-test="unlink-project-{{ $project->id }}" />
                                    @endcan
                                </div>
                            </div>

                            {{-- Two measures on one line: milestones reached, tasks done. --}}
                            <div class="mt-3 grid gap-x-8 gap-y-3 @2xl/detail:grid-cols-2">
                                {{-- Both bars mean the same thing — finished against committed — so both fill in the same colour. --}}
                                @foreach ([
                                    ['key' => 'milestones', 'label' => __('common.milestones'), 'done' => $project->reached_milestones_count, 'total' => $project->milestones_count],
                                    ['key' => 'tasks', 'label' => __('common.tasks'), 'done' => $projectDone, 'total' => $projectTasks->count()],
                                ] as $measure)
                                    <div class="min-w-0" data-measure="{{ $measure['key'] }}">
                                        <p class="flex items-baseline justify-between gap-2 text-xs">
                                            <span class="type-label {{ $mutedText }}">{{ $measure['label'] }}</span>
                                            <span class="font-bold tabular-nums {{ $measure['total'] === 0 ? $mutedText : $inkText }}">{{ $measure['total'] === 0 ? '—' : $measure['done'].' / '.$measure['total'] }}</span>
                                        </p>
                                        @php
                                            $slots = min(max($measure['total'], 1), 20);
                                            $filled = $measure['total'] > 0 ? (int) round($measure['done'] / $measure['total'] * $slots) : 0;
                                        @endphp
                                        <div class="mt-1.5 flex h-1.5 w-full gap-0.5" aria-hidden="true">
                                            @for ($slot = 0; $slot < $slots; $slot++)
                                                <span @class(['h-full flex-1 rounded-full', 'bg-status-good' => $slot < $filled, 'bg-secondary-soft dark:bg-secondary-medium' => $slot >= $filled])></span>
                                            @endfor
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        @elseif ($tab === 'tasks')
            <div class="mb-6 flex flex-wrap items-center justify-end gap-2">
                <x-ui.button tone="primary" size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openAddTaskModal" data-test="add-proposal-task-button">{{ __('tasks.add_task') }}</x-ui.button>
            </div>

            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('proposals.tasks_delivering_against_this_proposal_will_appear_here')">
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    $fundedProjectIds = $this->projects->modelKeys();
                    // Open work first, soonest due first; finished work sinks to the bottom of its group.
                    $ordered = $this->tasks->sortBy([
                        fn (Task $a, Task $b): int => ($a->status === TaskStatus::Done) <=> ($b->status === TaskStatus::Done),
                        fn (Task $a, Task $b): int => ($a->due_date === null) <=> ($b->due_date === null),
                        fn (Task $a, Task $b): int => ($a->due_date?->getTimestamp() ?? 0) <=> ($b->due_date?->getTimestamp() ?? 0),
                        fn (Task $a, Task $b): int => $a->id <=> $b->id,
                    ]);
                    $groups = $this->projects
                        ->map(fn ($project): array => [
                            'project' => $project,
                            'tasks' => $ordered->filter(fn (Task $task): bool => $task->sourceProjectId() === $project->id)->values(),
                        ])
                        ->filter(fn (array $group): bool => $group['tasks']->isNotEmpty())
                        ->values();
                    $looseTasks = $ordered->filter(fn (Task $task): bool => $task->sourceProjectId() === null || ! in_array($task->sourceProjectId(), $fundedProjectIds, true))->values();
                    $taskTotal = $this->tasks->count();
                    $statusCounts = collect(TaskStatus::cases())->map(fn (TaskStatus $status): array => [
                        'status' => $status,
                        'count' => $this->tasks->where('status', $status)->count(),
                    ]);
                    $overdueCount = $this->tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()))->count();
                    $unassignedCount = $this->tasks->filter(fn (Task $task): bool => $task->taskAssignees->isEmpty())->count();
                    $undatedCount = $this->tasks->whereNull('due_date')->count();
                    $statusTone = [
                        TaskStatus::Done->value => 'bg-secondary-dark dark:bg-white',
                        TaskStatus::UnderReview->value => 'bg-secondary-medium dark:bg-secondary-soft',
                        TaskStatus::InProgress->value => 'bg-secondary-light',
                        TaskStatus::Todo->value => 'bg-secondary-soft dark:bg-secondary-medium',
                    ];
                @endphp

                {{-- Where the work stands: one bar split by status, then the three gaps worth chasing. --}}
                <div class="flex flex-col gap-5 border-b pb-6 {{ $hairline }} @3xl/detail:flex-row @3xl/detail:items-end @3xl/detail:justify-between @3xl/detail:gap-10" data-task-overview>
                    <div class="min-w-0 flex-1">
                        <div class="flex h-2 w-full gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ $statusCounts->map(fn (array $line): string => $line['status']->label().' '.$line['count'])->implode(', ') }}">
                            @foreach ($statusCounts->reverse() as $line)
                                @if ($line['count'] > 0)
                                    <span class="money-grow h-full first:rounded-s-full last:rounded-e-full {{ $statusTone[$line['status']->value] }}" style="width: {{ round($line['count'] / $taskTotal * 100, 2) }}%"></span>
                                @endif
                            @endforeach
                        </div>
                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                            @foreach ($statusCounts as $line)
                                <div class="flex items-center gap-2" wire:key="task-status-{{ $line['status']->value }}" data-status-count="{{ $line['status']->value }}">
                                    <span class="size-2 rounded-full {{ $statusTone[$line['status']->value] }}" aria-hidden="true"></span>
                                    <dt class="text-xs {{ $mutedText }}">{{ $line['status']->label() }}</dt>
                                    <dd @class(['text-sm font-bold tabular-nums', $inkText => $line['count'] > 0, $mutedText => $line['count'] === 0])>{{ $line['count'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    <dl class="flex shrink-0 gap-x-8">
                        @foreach ([
                            ['key' => 'overdue', 'label' => __('proposals.overdue'), 'count' => $overdueCount, 'alarm' => $overdueCount > 0],
                            ['key' => 'unassigned', 'label' => __('common.unassigned'), 'count' => $unassignedCount, 'alarm' => false],
                            ['key' => 'undated', 'label' => __('proposals.no_due_date'), 'count' => $undatedCount, 'alarm' => false],
                        ] as $gap)
                            <div class="min-w-0" data-task-gap="{{ $gap['key'] }}">
                                <dt class="type-label {{ $mutedText }}">{{ $gap['label'] }}</dt>
                                <dd @class([
                                    'mt-1 text-2xl leading-none font-extrabold tracking-tight tabular-nums',
                                    'text-status-critical' => $gap['alarm'],
                                    $inkText => ! $gap['alarm'] && $gap['count'] > 0,
                                    $mutedText => $gap['count'] === 0,
                                ])>{{ $gap['count'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Column names, shown only once the rows spread into columns. --}}
                <div class="mt-4 hidden grid-cols-[1.25rem_minmax(0,1fr)_11rem_6rem_7.5rem_8rem_7rem] gap-x-4 @3xl/detail:grid" aria-hidden="true">
                    <span></span>
                    @foreach ([__('tasks.task'), __('tasks.assignee'), __('tasks.priority'), __('tasks.due'), __('tasks.progress'), __('common.status')] as $column)
                        <span @class(['type-label', $mutedText, 'text-end' => $loop->last])>{{ $column }}</span>
                    @endforeach
                </div>

                <div class="mt-2 flex flex-col gap-8">
                    @foreach ($groups as $group)
                        @php
                            $groupDone = $group['tasks']->where('status', TaskStatus::Done)->count();
                            $groupTotal = $group['tasks']->count();
                        @endphp
                        <section class="min-w-0" wire:key="task-group-{{ $group['project']->id }}" data-task-group="{{ $group['project']->id }}">
                            <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b pt-4 pb-3 {{ $hairline }}">
                                <a href="{{ route('projects.show', $group['project']) }}" wire:navigate class="min-w-0 truncate text-base font-bold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}">{{ $group['project']->name }}</a>
                                <span class="flex shrink-0 items-center gap-3 text-xs {{ $mutedText }}">
                                    <span class="h-1 w-24 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-secondary-dark dark:bg-white" style="width: {{ round($groupDone / $groupTotal * 100, 2) }}%"></span>
                                    </span>
                                    <span class="tabular-nums">{{ __('proposals.done_of_total', ['done' => $groupDone, 'total' => $groupTotal]) }}</span>
                                </span>
                            </header>
                            <ul class="divide-y {{ $hairline }}">
                                @foreach ($group['tasks'] as $task)
                                    @include('pages.proposals._task-row', ['task' => $task, 'inkText' => $inkText, 'mutedText' => $mutedText])
                                @endforeach
                            </ul>
                        </section>
                    @endforeach

                    @if ($looseTasks->isNotEmpty())
                        <section class="min-w-0" data-loose-tasks>
                            <header class="border-b pt-4 pb-3 {{ $hairline }}">
                                <p class="text-base font-bold {{ $inkText }}">{{ __('proposals.work_not_tied_to_a_funded_project') }}</p>
                                <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.tasks_with_no_milestone_on_a_funded_project') }}</p>
                            </header>
                            <ul class="divide-y {{ $hairline }}">
                                @foreach ($looseTasks as $task)
                                    @include('pages.proposals._task-row', ['task' => $task, 'inkText' => $inkText, 'mutedText' => $mutedText])
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($undatedCount > 0)
                        <p class="text-xs {{ $mutedText }}" data-undated>
                            {{ trans_choice('proposals.count_tasks_carry_no_due_date', $undatedCount, ['count' => $undatedCount]) }}
                        </p>
                    @endif
                </div>
            @endif
        @elseif ($tab === 'targets')
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('proposals.indicator_targets_committed_in_this_proposal_will_appear_her')" />
            @else
                <div class="flex flex-col gap-7">
                    @foreach ($this->targets as $target)
                        @php
                            $achieved = $target->achievedValue();
                            $pendingValue = $target->pendingValue();
                            $targetValue = (float) $target->target;
                            $achievedPercent = $targetValue > 0 ? min(100, round($achieved / $targetValue * 100, 2)) : 0;
                            $pendingPercent = $targetValue > 0 ? min(100 - $achievedPercent, round($pendingValue / $targetValue * 100, 2)) : 0;
                            $issues = $target->chainIssues();
                        @endphp
                        <article class="min-w-0" wire:key="target-{{ $target->id }}" data-target="{{ $target->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0">
                                    <p class="text-base font-bold {{ $inkText }}">{{ $target->indicator->name }}</p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs {{ $mutedText }}">
                                        <span>{{ $target->project?->name ?? __('common.organisation_wide') }}</span>
                                        @if ($target->indicator->result !== null)
                                            <span aria-hidden="true">·</span>
                                            <span class="truncate">{{ $target->indicator->result->statement }}</span>
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-baseline gap-2">
                                    <x-ui.enum-badge :case="$target->period" />
                                    <span class="text-xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ number_format($targetValue, 2) }}</span>
                                </div>
                            </div>

                            <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ __('proposals.reported_of_target', ['reported' => number_format($achieved, 2), 'target' => number_format($targetValue, 2)]) }}">
                                <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $achievedPercent }}%"></span>
                                <span class="money-grow money-pending h-full" style="width: {{ $pendingPercent }}%"></span>
                            </div>

                            <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                @if ($target->reports->isEmpty())
                                    <span class="font-semibold text-status-critical">{{ __('proposals.not_reported_yet') }}</span>
                                @else
                                    <span><span class="font-bold text-funding">{{ number_format($achieved, 2) }}</span> {{ __('proposals.reported_lower') }}</span>
                                    <span>{{ trans_choice('common.count_report', $target->reports->count(), ['count' => $target->reports->count()]) }}</span>
                                @endif
                            </p>

                            @foreach ($issues as $issue)
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-status-critical" wire:key="issue-{{ $target->id }}-{{ $loop->index }}">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />
                                    {{ __($issue) }}
                                </p>
                            @endforeach
                        </article>
                    @endforeach
                </div>
            @endif
        @elseif ($tab === 'deadlines')
            @if ($this->deadlines === [])
                <x-ui.empty-state icon="calendar-days" :heading="__('proposals.nothing_is_due')" :body="__('proposals.tranches_reports_tasks_and_end_dates_still_open_will_appear_here')" />
            @else
                @php
                    $deadlineGroups = collect($this->deadlines)->groupBy('state');
                    $kindLabel = [
                        'transfer' => __('proposals.deadline_kind_transfer'),
                        'donor-report' => __('proposals.deadline_kind_donor_report'),
                        'report' => __('proposals.deadline_kind_report'),
                        'task' => __('proposals.deadline_kind_task'),
                        'project' => __('proposals.deadline_kind_project'),
                        'grant' => __('proposals.deadline_kind_grant'),
                    ];
                    $kindIcon = ['transfer' => 'banknotes', 'donor-report' => 'paper-airplane', 'report' => 'document-chart-bar', 'task' => 'clipboard-document-check', 'project' => 'rectangle-stack', 'grant' => 'flag'];
                @endphp
                <div class="flex flex-col gap-8" data-deadlines>
                    @foreach (['overdue', 'soon', 'later'] as $state)
                        @continue(! $deadlineGroups->has($state))
                        <section class="min-w-0" aria-labelledby="deadlines-{{ $state }}" wire:key="deadline-group-{{ $state }}" data-deadline-group="{{ $state }}">
                            <h2 id="deadlines-{{ $state }}" @class(['type-label', 'text-status-critical' => $state === 'overdue', $mutedText => $state !== 'overdue'])>
                                {{ match ($state) { 'overdue' => __('proposals.deadline_group_overdue'), 'soon' => __('proposals.deadline_group_soon', ['days' => 30]), default => __('proposals.deadline_group_later') } }} · {{ $deadlineGroups[$state]->count() }}
                            </h2>
                            <ol class="mt-3 flex flex-col">
                                @foreach ($deadlineGroups[$state] as $item)
                                    <li class="relative flex min-w-0 gap-3 pb-4 last:pb-0" wire:key="deadline-{{ $item['key'] }}" data-deadline="{{ $item['key'] }}">
                                        <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                            @unless ($loop->last)
                                                <span class="absolute top-3 bottom-[-1rem] w-px bg-secondary-soft dark:bg-secondary-medium"></span>
                                            @endunless
                                            <span @class([
                                                'relative z-10 mt-1.5 size-2.5 rounded-full',
                                                'bg-primary' => $state === 'overdue',
                                                'border-2 border-status-warn bg-white dark:bg-secondary-dark' => $state === 'soon',
                                                'border-2 border-secondary-light bg-white dark:bg-secondary-dark' => $state === 'later',
                                            ])></span>
                                        </span>
                                        <div class="flex min-w-0 flex-1 flex-col gap-1 @xl/detail:flex-row @xl/detail:items-baseline @xl/detail:gap-4">
                                            <time datetime="{{ $item['date']->toDateString() }}" class="shrink-0 text-sm font-bold tabular-nums @xl/detail:w-28 {{ $inkText }}">{{ $item['date']->translatedFormat(__('formats.date')) }}</time>
                                            <div class="min-w-0 flex-1">
                                                <p class="flex min-w-0 items-center gap-1.5 text-sm">
                                                    <flux:icon :name="$kindIcon[$item['kind']]" variant="micro" class="size-4 shrink-0 {{ $mutedText }}" aria-hidden="true" />
                                                    @if ($item['url'] !== null)
                                                        <a href="{{ $item['url'] }}" wire:navigate class="truncate font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}">{{ $item['title'] }}</a>
                                                    @else
                                                        <span class="truncate font-semibold {{ $inkText }}">{{ $item['title'] }}</span>
                                                    @endif
                                                </p>
                                                <p class="mt-0.5 truncate text-xs {{ $mutedText }}">{{ $kindLabel[$item['kind']] }} · {{ $item['detail'] }}</p>
                                            </div>
                                            <span @class(['shrink-0 text-xs font-semibold tabular-nums', 'text-status-critical' => $state === 'overdue', 'text-status-warn' => $state === 'soon', $mutedText => $state === 'later'])>
                                                @if ($item['days'] < 0)
                                                    {{ trans_choice('proposals.count_days_late', -$item['days'], ['count' => -$item['days']]) }}
                                                @elseif ($item['days'] === 0)
                                                    {{ __('proposals.due_today') }}
                                                @else
                                                    {{ trans_choice('proposals.due_in_count_days', $item['days'], ['count' => $item['days']]) }}
                                                @endif
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endforeach
                </div>
            @endif
        @elseif ($tab === 'reports')
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <p class="max-w-prose text-xs {{ $mutedText }}">{{ __('proposals.reports_filed_against_the_targets_this_proposal_commits') }}</p>
                @if ($this->targets->isNotEmpty())
                    <a href="{{ route('reports.index', ['create' => 1]) }}" wire:navigate class="inline-flex min-h-10 touch-manipulation items-center gap-1.5 rounded-lg bg-primary px-3 text-sm font-semibold text-white hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-test="record-report">
                        <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />
                        {{ __('proposals.record_a_report') }}
                    </a>
                @endif
            </div>

            @if ($this->reports->isEmpty())
                <x-ui.empty-state icon="document-chart-bar" :heading="__('proposals.no_reports_yet')" :body="__('proposals.reports_on_this_proposal_s_targets_will_appear_here')" />
            @else
                @php
                    $today = \Carbon\CarbonImmutable::today();
                    $owed = $this->reports->filter(fn (Report $report): bool => $this->reportIsOwed($report));
                    $reportTally = [
                        ['key' => 'late', 'label' => __('proposals.owed_and_late'), 'count' => $owed->filter(fn (Report $report): bool => $report->planned_date->lt($today))->count(), 'alarm' => true],
                        ['key' => 'owed', 'label' => __('proposals.owed_on_time'), 'count' => $owed->filter(fn (Report $report): bool => ! $report->planned_date->lt($today))->count(), 'alarm' => false],
                        ['key' => 'review', 'label' => __('proposals.in_review'), 'count' => $this->reports->filter(fn (Report $report): bool => ! $this->reportIsOwed($report) && $report->status !== ReportStatus::Approved)->count(), 'alarm' => false],
                        ['key' => 'approved', 'label' => ReportStatus::Approved->label(), 'count' => $this->reports->where('status', ReportStatus::Approved)->count(), 'alarm' => false],
                    ];
                @endphp
                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-b pb-6 {{ $hairline }} @2xl/detail:grid-cols-4" data-report-tally>
                    @foreach ($reportTally as $tally)
                        <div class="min-w-0" data-report-tally-item="{{ $tally['key'] }}">
                            <dt class="type-label {{ $mutedText }}">{{ $tally['label'] }}</dt>
                            <dd @class(['mt-1 text-2xl leading-none font-extrabold tracking-tight tabular-nums', 'text-status-critical' => $tally['alarm'] && $tally['count'] > 0, $inkText => ! ($tally['alarm'] && $tally['count'] > 0) && $tally['count'] > 0, $mutedText => $tally['count'] === 0])>{{ $tally['count'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <ul class="divide-y {{ $hairline }}" data-report-list>
                    @foreach ($this->reports as $report)
                        @php
                            $isOwed = $this->reportIsOwed($report);
                            $isLate = $isOwed && $report->planned_date->lt($today);
                        @endphp
                        <li class="flex min-w-0 flex-col gap-2 py-4 @2xl/detail:flex-row @2xl/detail:items-center @2xl/detail:gap-6" wire:key="report-{{ $report->id }}" data-report="{{ $report->id }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('reports.show', $report->id) }}" wire:navigate class="line-clamp-2 text-sm font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}">{{ $report->planIndicator->indicator->name }}</a>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                    <span>{{ $report->planIndicator->project?->name ?? __('common.organisation_wide') }}</span>
                                    @if ($report->period_start !== null && $report->period_end !== null)
                                        <span aria-hidden="true">·</span>
                                        <span>{{ __('proposals.period_range', ['from' => $report->period_start->translatedFormat(__('formats.date_short')), 'to' => $report->period_end->translatedFormat(__('formats.date_short'))]) }}</span>
                                    @endif
                                    <span aria-hidden="true">·</span>
                                    <span>{{ __('proposals.value_reported', ['value' => number_format((float) $report->value, 2)]) }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums">
                                <span @class(['font-semibold text-status-critical' => $isLate, $mutedText => ! $isLate])>{{ __('proposals.due_on', ['date' => $report->planned_date->translatedFormat(__('formats.date'))]) }}</span>
                                @if ($report->submit_date !== null)
                                    <span class="{{ $mutedText }}">{{ __('proposals.submitted_on', ['date' => $report->submit_date->translatedFormat(__('formats.date'))]) }}</span>
                                @endif
                                <x-ui.enum-badge :case="$report->status" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($tab === 'documents')
            <div class="flex flex-col gap-10" data-documents>
                <section class="min-w-0" aria-labelledby="own-files-heading" data-own-files>
                    <div class="flex flex-wrap items-end justify-between gap-3 border-b pb-3 {{ $hairline }}">
                        <div class="min-w-0">
                            <h2 id="own-files-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.filed_on_this_proposal') }}</h2>
                            <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.concept_note_proposal_agreement_mou_and_anything_else') }}</p>
                        </div>
                        @can('update', $this->proposal)
                        <x-ui.button tone="primary" size="sm" icon="arrow-up-tray" class="min-h-10 touch-manipulation" wire:click="openCreateFileModal" data-test="upload-proposal-file">{{ __('proposals.upload_file') }}</x-ui.button>
                        @endcan
                    </div>

                    @if ($this->proposalFiles->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_files_filed_on_this_proposal_yet') }}</p>
                    @else
                        <ul class="divide-y {{ $hairline }}">
                            @foreach ($this->proposalFiles as $media)
                                @include('pages.proposals._file-row', ['media' => $media, 'proposalId' => $this->proposalId, 'downloadUrl' => $this->documentDownloadUrl($media), 'owner' => null, 'editable' => auth()->user()->can('update', $this->proposal), 'inkText' => $inkText, 'mutedText' => $mutedText])
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="min-w-0" aria-labelledby="project-files-heading" data-project-files>
                    <div class="border-b pb-3 {{ $hairline }}">
                        <h2 id="project-files-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.from_funded_projects') }}</h2>
                        <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.grant_paperwork_filed_on_the_projects_this_proposal_funds') }}</p>
                    </div>

                    @if ($this->projectFiles->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_grant_paperwork_on_funded_projects') }}</p>
                    @else
                        <ul class="divide-y {{ $hairline }}">
                            @foreach ($this->projectFiles as $media)
                                @include('pages.proposals._file-row', ['media' => $media, 'proposalId' => $this->proposalId, 'downloadUrl' => $this->documentDownloadUrl($media), 'owner' => $media->mediable, 'editable' => false, 'inkText' => $inkText, 'mutedText' => $mutedText])
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @else
            {{-- Why the status moved, and whatever was written down about this proposal. --}}
            <div class="grid gap-x-10 gap-y-8 @3xl/detail:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <section aria-labelledby="moves-heading" class="min-w-0" data-status-history>
                    <h2 id="moves-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.status_history') }}</h2>
                    <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.the_audit_trail_for_this_proposal_s_status_newest_first') }}</p>

                    @if ($this->statusNotes->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">
                            <span class="font-semibold {{ $inkText }}">{{ __('proposals.no_status_history_yet') }}</span>
                            {{ __('proposals.use_change_status_to_record_the_first_movement_and_why_it_ha') }}
                        </p>
                    @else
                        <ol class="mt-4 flex flex-col">
                            @foreach ($this->statusNotes as $note)
                                <li class="relative flex min-w-0 gap-3 pb-5 last:pb-0" wire:key="status-note-{{ $note->id }}">
                                    <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                        @unless ($loop->last)
                                            <span class="absolute top-3 bottom-[-1.25rem] w-px bg-secondary-soft dark:bg-secondary-medium"></span>
                                        @endunless
                                        <span @class(['relative z-10 mt-1.5 size-2.5 rounded-full', 'bg-primary' => $loop->first, 'bg-secondary-light' => ! $loop->first])></span>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                            <x-ui.enum-badge :case="$note->status" />
                                            <time datetime="{{ $note->date?->toDateString() }}" class="text-xs tabular-nums {{ $mutedText }}">{{ $note->date?->translatedFormat(__('formats.date')) }}</time>
                                        </div>
                                        @if ($note->context !== null)
                                            <p class="mt-1.5 text-sm {{ $inkText }}">{{ $note->context }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                <section aria-labelledby="notes-heading" class="min-w-0" data-notes>
                    <h2 id="notes-heading" class="text-lg font-bold {{ $inkText }}">{{ __('common.notes') }}</h2>
                    @if (filled($this->proposal->notes))
                        <p class="mt-3 max-w-[70ch] text-sm leading-relaxed whitespace-pre-line {{ $inkText }}">{{ $this->proposal->notes }}</p>
                    @else
                        <p class="mt-3 text-sm {{ $mutedText }}">{{ __('proposals.nothing_was_written_down_about_this_proposal') }}</p>
                    @endif
                    <p class="mt-5 text-xs tabular-nums {{ $mutedText }}">{{ __('common.updated_date', ['date' => $this->proposal->updated_at?->translatedFormat(__('formats.date')) ?? '—']) }}</p>
                </section>
            </div>
        @endif
    </div>

    <flux:modal wire:model="showStatusModal" class="w-full max-w-xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="changeStatus" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('proposals.change_status') }}</flux:heading>
                <flux:subheading>{{ __('proposals.the_new_status_and_your_reason_are_recorded_together_as_one') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="newStatus" :label="__('proposals.new_status')" required>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'change-status-'.$statusCase->value">
                        {{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <flux:textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <x-ui.input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" required />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="$set('showStatusModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-status-button">{{ __('proposals.record_change') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDetailsModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveDetails" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('proposals.edit_details') }}</flux:heading>
                <flux:subheading>{{ __('proposals.status_moves_through_change_status_so_it_is_always_recorded') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="detailsName" :label="__('proposals.proposal_name')" type="text" maxlength="255" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="detailsPlannedBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.input wire:model="detailsPlannedEnd" :label="__('proposals.planned_end')" type="date" required />
                <x-ui.input wire:model="detailsActualBegin" :label="__('proposals.actual_start')" type="date" />
                <x-ui.input wire:model="detailsActualEnd" :label="__('proposals.actual_end')" type="date" />
            </div>

            <flux:textarea wire:model="detailsNotes" :label="__('common.notes')" rows="4" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="$set('showDetailsModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-details-button">{{ __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <x-funding.agreement-modal
        :editing="$editingAgreementId !== null"
        :donor-options="$showAgreementModal ? $this->donorOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :locked-proposal-name="$this->proposal->name"
    />

    <x-funding.transfer-modal
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />

    <flux:modal wire:model="showUnlinkModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('proposals.stop_funding_this_project') }}</flux:heading>
            @if ($unlinkingProjectId !== null && ($unlinking = $this->projects->firstWhere('id', $unlinkingProjectId)) !== null)
                <flux:subheading>{{ __('proposals.unlink_project_consequence', ['name' => $unlinking->name]) }}</flux:subheading>
            @endif
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('showUnlinkModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="unlinkProject" data-test="confirm-unlink-project">{{ __('proposals.unlink_project') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showFileModal" wire:close="closeFileModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showFileModal)
            <form wire:submit="saveFile" class="space-y-5">
                <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                    <flux:heading size="lg">{{ $editingFileId === null ? __('proposals.upload_file') : __('proposals.edit_file') }}</flux:heading>
                    <flux:subheading>{{ __('reports.allowed_file_types_and_size', ['types' => \Illuminate\Support\Str::upper(implode(', ', FileType::values())), 'size' => '5 MB']) }}</flux:subheading>
                </div>

                <div>
                    <x-ui.input
                        type="file"
                        wire:model="newFile"
                        :label="$editingFileId === null ? __('proposals.choose_a_file') : __('reports.replace_file_optional')"
                        accept="{{ collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',') }}"
                        :required="$editingFileId === null"
                    />
                    <p wire:loading wire:target="newFile" class="mt-1.5 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('reports.uploading') }}</p>
                </div>

                <x-ui.select wire:model="fileCategory" :label="__('proposals.file_category')" required>
                    <flux:select.option value="">{{ __('common.select') }}</flux:select.option>
                    @foreach (MediaCollection::cases() as $collectionOption)
                        <flux:select.option :value="$collectionOption->value" :wire:key="'file-category-option-'.$collectionOption->value">{{ $collectionOption->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.input wire:model="fileDisplayName" :label="__('proposals.file_display_name')" :placeholder="$editingFileId === null ? __('reports.defaults_to_the_uploaded_file_name') : null" :required="$editingFileId !== null" />

                <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                    <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeFileModal">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:loading.attr="disabled" wire:target="newFile,saveFile" data-test="save-proposal-file-button">
                        {{ $editingFileId === null ? __('proposals.upload_file') : __('common.save_changes') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal wire:model="showPreviewModal" wire:close="closePreview" class="w-full max-w-5xl border border-secondary-light! bg-white! p-0! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showPreviewModal && ($previewFile = $this->documents->firstWhere('id', $previewingFileId)) !== null)
            @php
                $previewName = $previewFile->original_name;
                $previewUrl = route('proposals.files.preview', [$this->proposalId, $previewFile]);
                $previewDownloadUrl = $this->documentDownloadUrl($previewFile);
                $previewPosition = $this->documents->search(fn ($candidate) => $candidate->id === $previewFile->id) + 1;
            @endphp
            <div
                class="flex max-h-[90dvh] flex-col"
                wire:key="preview-{{ $previewFile->id }}"
                x-data
                x-on:keydown.left.window="$wire.previewAdjacent(document.dir === 'rtl' ? 1 : -1)"
                x-on:keydown.right.window="$wire.previewAdjacent(document.dir === 'rtl' ? -1 : 1)"
                data-preview
            >
                <div class="flex flex-wrap items-center gap-3 border-b border-secondary-soft py-3 ps-5 pe-14 dark:border-secondary-medium">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="lg" class="truncate" title="{{ $previewName }}">{{ $previewName }}</flux:heading>
                        <p class="flex flex-wrap items-center gap-x-1.5 text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                            <span class="font-bold">{{ \Illuminate\Support\Str::upper($previewFile->type->value) }}</span>
                            <span aria-hidden="true">&middot;</span>
                            <span>{{ $previewFile->collection->label() }}</span>
                            @if ($previewFile->mediable_type === 'project' && $previewFile->mediable !== null)
                                <span aria-hidden="true">&middot;</span>
                                <span>{{ $previewFile->mediable->name }}</span>
                            @endif
                            @if ($previewFile->size !== null)
                                <span aria-hidden="true">&middot;</span>
                                <span>{{ \Illuminate\Support\Number::fileSize($previewFile->size, precision: 1) }}</span>
                            @endif
                            @if ($this->documents->count() > 1)
                                <span aria-hidden="true">&middot;</span>
                                <span>{{ __('reports.preview_position', ['position' => $previewPosition, 'total' => $this->documents->count()]) }}</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        @if ($this->documents->count() > 1)
                            <x-ui.button tone="ghost" size="sm" icon="chevron-left" class="size-9! touch-manipulation rtl:[&_svg]:-scale-x-100" wire:click="previewAdjacent(-1)" :aria-label="__('reports.previous_file')" />
                            <x-ui.button tone="ghost" size="sm" icon="chevron-right" class="size-9! touch-manipulation rtl:[&_svg]:-scale-x-100" wire:click="previewAdjacent(1)" :aria-label="__('reports.next_file')" />
                        @endif
                        @if ($previewFile->isPreviewable())
                            <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="inline-flex size-9 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white" aria-label="{{ __('reports.open_in_new_tab') }}">
                                <flux:icon name="arrow-top-right-on-square" variant="micro" class="size-4" />
                            </a>
                        @endif
                        <a href="{{ $previewDownloadUrl }}" class="inline-flex size-9 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white" aria-label="{{ __('common.download_name', ['name' => $previewName]) }}">
                            <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                        </a>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-auto bg-board-head dark:bg-secondary-medium/20">
                    @if (! $previewFile->isStored())
                        <x-ui.empty-state icon="eye-slash" :heading="__('reports.preview_not_available')" :body="__('reports.this_record_has_no_stored_file')" />
                    @elseif ($previewFile->type === FileType::Pdf)
                        <iframe src="{{ $previewUrl }}#view=FitH" title="{{ $previewName }}" class="h-[75dvh] w-full border-0 bg-white" data-test="pdf-preview"></iframe>
                    @elseif ($previewFile->isPreviewable())
                        <div class="flex min-h-[50dvh] items-center justify-center p-4">
                            <img src="{{ $previewUrl }}" alt="{{ $previewName }}" class="max-h-[75dvh] max-w-full rounded-lg object-contain shadow-sm" data-test="image-preview" />
                        </div>
                    @else
                        {{-- Word/Excel: rendered client-side by resources/js/file-preview.js from the authenticated download route. --}}
                        <div
                            wire:ignore
                            x-data="officePreview(@js(['url' => $previewDownloadUrl, 'type' => $previewFile->type->value]))"
                            class="flex h-[75dvh] flex-col"
                            data-test="office-preview"
                        >
                            <div x-show="sheets.length > 1" class="flex shrink-0 gap-1 overflow-x-auto border-b border-secondary-soft bg-white px-3 py-2 dark:border-secondary-medium dark:bg-secondary-dark">
                                <template x-for="(sheetName, index) in sheets" :key="index">
                                    <button
                                        type="button"
                                        x-on:click="showSheet(index)"
                                        x-text="sheetName"
                                        class="shrink-0 rounded-md px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                        :class="index === activeSheet ? 'bg-primary text-white' : 'text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium'"
                                    ></button>
                                </template>
                            </div>

                            <div x-show="status === 'loading'" class="flex flex-1 items-center justify-center gap-2 text-sm text-secondary-medium dark:text-secondary-soft">
                                <flux:icon.loading class="size-5" />
                                {{ __('reports.loading_preview') }}
                            </div>

                            <div x-show="status === 'error'" x-cloak class="flex-1">
                                <x-ui.empty-state icon="exclamation-triangle" :heading="__('reports.preview_not_available')" :body="__('reports.this_file_could_not_be_previewed')">
                                    <x-slot:action>
                                        <a href="{{ $previewDownloadUrl }}" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
                                            <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                                            {{ __('common.download_name', ['name' => $previewName]) }}
                                        </a>
                                    </x-slot:action>
                                </x-ui.empty-state>
                            </div>

                            <div x-show="status === 'ready'" x-cloak x-ref="canvas" class="min-h-0 flex-1 overflow-auto"></div>

                            <p x-show="truncated" x-cloak class="shrink-0 border-t border-secondary-soft bg-white px-3 py-2 text-xs text-secondary-medium dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">{{ __('reports.sheet_preview_truncated', ['rows' => 500]) }}</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model="confirmingFileDelete" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('proposals.delete_this_file') }}</flux:heading>
            @if ($deletingFileId !== null && ($deletingMedia = $this->proposalFiles->firstWhere('id', $deletingFileId)) !== null)
                <flux:subheading>{{ __('proposals.deleting_the_file_also_removes_it_from_storage', ['name' => $deletingMedia->original_name]) }}</flux:subheading>
            @endif
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('confirmingFileDelete', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteFile" data-test="confirm-delete-proposal-file">{{ __('proposals.delete_this_file') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>

    @php
        $selectedNewTaskAssigneeOptions = $this->userOptions->whereIn('id', collect($newTaskAssignedTo)->map(fn ($id) => (int) $id));
        $newTaskAssigneeTriggerLabel = match (true) {
            $selectedNewTaskAssigneeOptions->isEmpty() => __('common.select'),
            $selectedNewTaskAssigneeOptions->count() <= 2 => $selectedNewTaskAssigneeOptions->pluck('name')->implode(', '),
            default => __('common.count_selected', ['count' => $selectedNewTaskAssigneeOptions->count()]),
        };
    @endphp

    <flux:modal name="proposal-add-task-modal" wire:model="showAddTaskModal" wire:close="closeAddTaskModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="addTask" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('tasks.add_task') }}</flux:heading>
            </div>

            <x-ui.input wire:model="newTaskTitle" :label="__('tasks.task_title')" type="text" maxlength="255" required autofocus data-test="new-proposal-task-title-input" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" id="new-proposal-task-status-label">{{ __('common.status') }}</label>
                    <div class="grid grid-cols-2 gap-1.5" role="radiogroup" aria-labelledby="new-proposal-task-status-label">
                        @foreach (TaskStatus::cases() as $statusCase)
                            @php $isNewTaskStatusSelected = $newTaskStatus === $statusCase->value; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $isNewTaskStatusSelected ? 'true' : 'false' }}"
                                wire:click="$set('newTaskStatus', '{{ $statusCase->value }}')"
                                @class([
                                    'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $isNewTaskStatusSelected,
                                    'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isNewTaskStatusSelected,
                                ])
                                data-test="new-proposal-task-status-{{ $statusCase->value }}"
                            >
                                {{ $statusCase->label() }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="newTaskStatus" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft" id="new-proposal-task-priority-label">{{ __('tasks.priority') }}</label>
                    <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-labelledby="new-proposal-task-priority-label">
                        @foreach (TaskPriority::cases() as $priorityCase)
                            @php $isNewTaskPrioritySelected = $newTaskPriority === $priorityCase->value; @endphp
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $isNewTaskPrioritySelected ? 'true' : 'false' }}"
                                wire:click="$set('newTaskPriority', '{{ $priorityCase->value }}')"
                                @class([
                                    'inline-flex min-h-10 cursor-pointer touch-manipulation items-center justify-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-semibold whitespace-nowrap transition-colors',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-secondary-medium dark:bg-secondary-medium' => $isNewTaskPrioritySelected,
                                    'border-secondary-light text-secondary-medium hover:border-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => ! $isNewTaskPrioritySelected,
                                ])
                                data-test="new-proposal-task-priority-{{ $priorityCase->value }}"
                            >
                                {{ $priorityCase->label() }}
                            </button>
                        @endforeach
                    </div>
                    <flux:error name="newTaskPriority" class="mt-1.5" />
                </div>
            </div>

            <div class="min-w-0">
                <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('tasks.assignees') }}</label>
                <x-ui.multi-select-popover
                    :label="__('tasks.assignees')"
                    target="[data-modal='proposal-add-task-modal']"
                    class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-secondary-light bg-white px-3 py-2 text-start text-sm dark:border-secondary-light dark:bg-secondary-medium"
                    data-test="new-proposal-task-assignee-picker"
                >
                    <x-slot:trigger>
                        <span class="min-w-0 truncate">{{ $newTaskAssigneeTriggerLabel }}</span>
                        <flux:icon.chevron-up-down class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" aria-hidden="true" />
                    </x-slot:trigger>

                    @foreach ($this->userOptions as $userOption)
                        <label
                            x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                        >
                            <input type="checkbox" wire:model.live="newTaskAssignedTo" value="{{ $userOption->id }}" class="rounded border-secondary-light text-primary focus:ring-primary" data-test="new-proposal-task-assignee-checkbox-{{ $userOption->id }}" />
                            <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                        </label>
                    @endforeach
                </x-ui.multi-select-popover>
                <flux:error name="newTaskAssignedTo" class="mt-1.5" />
            </div>

            <x-ui.input wire:model="newTaskDueDate" :label="__('tasks.due_date_2')" type="date" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeAddTaskModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-new-proposal-task-button">{{ __('tasks.add_task') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
