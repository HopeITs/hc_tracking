<?php

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.calendar')] class extends Component {
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $anchorDate = '';

    #[Url(except: 'month')]
    public string $viewMode = 'month';

    /** @var array<int, string> */
    #[Url(as: 'responsible', except: [])]
    public array $filterEmployeeIds = [];

    public bool $showMilestones = true;
    public bool $showTasks = true;
    public bool $showReports = true;
    public bool $showProposals = true;
    public bool $showTransfers = true;
    public bool $showDonors = true;
    public bool $showProjects = true;
    public bool $showTypeFilterMenu = false;

    public string $dayDetailDate = '';
    public bool $showDayDetailModal = false;

    public bool $showMilestoneModal = false;
    public bool $showDeleteModal = false;
    #[Locked]
    public ?int $editingMilestoneId = null;
    #[Locked]
    public ?int $deletingMilestoneId = null;
    public string $name = '';
    public string $plannedStartDate = '';
    public string $plannedEndDate = '';
    public string $milestoneStatus = 'planned';
    public string $sequence = '1';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $milestoneProjectId = '';
    public string $parentMilestoneId = '';

    public bool $showTaskModal = false;
    public bool $showTaskDeleteModal = false;
    #[Locked]
    public ?int $editingTaskId = null;
    #[Locked]
    public ?int $deletingTaskId = null;
    public string $title = '';
    public string $description = '';
    public string $taskStatus = 'todo';
    public string $taskPriority = 'medium';
    public string $startDate = '';
    public string $dueDate = '';
    public string $completedDate = '';
    public string $progressPercentage = '0';

    /** @var array<int, string> */
    public array $assignedTo = [];

    public string $taskSourceType = 'proposal';

    public string $taskSourceId = '';

    public function previousMonth(): void
    {
        $this->month = $this->activeMonth()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->activeMonth()->addMonthNoOverflow()->format('Y-m');
    }

    public function goToToday(): void
    {
        $this->month = '';
        $this->anchorDate = '';
    }

    /**
     * Switch between the month grid and the ClickUp-style day/4-days/week
     * time-grid views. Only the four values below are valid view modes; any
     * other value (e.g. a stale bookmarked URL) is silently ignored so the
     * component falls back to whatever $viewMode already holds.
     */
    public function setViewMode(string $viewMode): void
    {
        if (! in_array($viewMode, ['day', 'four_days', 'week', 'month'], true)) {
            return;
        }

        $this->viewMode = $viewMode;
    }

    public function previousPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->subDays($this->periodLengthInDays())->format('Y-m-d');
    }

    public function nextPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->addDays($this->periodLengthInDays())->format('Y-m-d');
    }

    protected function periodLengthInDays(): int
    {
        return match ($this->viewMode) {
            'day' => 1,
            'four_days' => 4,
            'week' => 7,
            default => 1,
        };
    }

    protected function activeAnchor(): CarbonImmutable
    {
        if ($this->anchorDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->anchorDate) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->anchorDate);

            if ($parsed !== false) {
                return $parsed->startOfDay();
            }
        }

        return CarbonImmutable::now()->startOfDay();
    }

    /**
     * The visible days for the day/4-days/week views. Week always starts on
     * the anchor's Sunday, so navigating never lands mid-week.
     *
     * @return array<int, CarbonImmutable>
     */
    protected function periodDays(): array
    {
        $anchor = $this->viewMode === 'week'
            ? $this->activeAnchor()->startOfWeek(CarbonImmutable::SUNDAY)
            : $this->activeAnchor();

        return array_map(
            fn (int $i): CarbonImmutable => $anchor->addDays($i),
            range(0, $this->periodLengthInDays() - 1),
        );
    }

    /**
     * The employee record for the signed-in user, if their account is linked
     * to one. `Milestone::responsible_user_id` holds an HR employee id, not a
     * `users.id`, so this is the bridge between "me" and "my milestones".
     */
    protected function employeeId(): ?int
    {
        return auth()->user()->employee_id;
    }

    /**
     * The employees whose items the calendar shows: everyone checked in the
     * responsible-user filter, defaulting to just the signed-in user's own
     * employee record when nothing is checked.
     *
     * @return array<int, int>
     */
    protected function scopedEmployeeIds(): array
    {
        if ($this->filterEmployeeIds !== []) {
            return array_map('intval', $this->filterEmployeeIds);
        }

        $employeeId = $this->employeeId();

        return $employeeId === null ? [] : [$employeeId];
    }

    /**
     * The employee a newly created milestone/task defaults to: the one
     * employee the filter is narrowed to, or the signed-in user's own record
     * when the filter covers several people (or none) and there is no single
     * obvious owner to default to.
     */
    protected function defaultResponsibleEmployeeId(): ?int
    {
        if (count($this->filterEmployeeIds) === 1) {
            return (int) $this->filterEmployeeIds[0];
        }

        return $this->employeeId();
    }

    protected function activeMonth(): CarbonImmutable
    {
        if ($this->month !== '' && preg_match('/^\d{4}-\d{2}$/', $this->month) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01');

            if ($parsed !== false) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * The calendar grid. In month view, full weeks covering the active
     * month (padded with the tail of adjacent months). In day/4-days/week
     * view, the plain list of visible days instead — `weeks` is null then
     * and `days` is populated, and vice versa in month view.
     *
     * @return array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: Collection<string, Collection<int, Milestone>>, tasksByDay: Collection<string, Collection<int, Task>>, reportsByDay: Collection<string, Collection<int, Report>>, proposalsByDay: Collection<string, Collection<int, Proposal>>, transfersByDay: Collection<string, Collection<int, TransfersTracking>>, donorFollowUpsByDay: Collection<string, Collection<int, Donor>>, donorReportsByDay: Collection<string, Collection<int, DonorReport>>, projectStartsByDay: Collection<string, Collection<int, Project>>, projectEndsByDay: Collection<string, Collection<int, Project>>, unscheduledTasks: Collection<int, Task>, nowOffsetPercent: float}
     */
    #[Computed]
    public function calendar(): array
    {
        if ($this->viewMode === 'month') {
            $month = $this->activeMonth();
            $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
            $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

            $weeks = [];
            $cursor = $gridStart;
            while ($cursor->lte($gridEnd)) {
                $week = [];
                for ($i = 0; $i < 7; $i++) {
                    $week[] = $cursor;
                    $cursor = $cursor->addDay();
                }
                $weeks[] = $week;
            }

            $days = null;
        } else {
            $days = $this->periodDays();
            $month = $days[0];
            $gridStart = $days[0];
            $gridEnd = end($days);
            $weeks = null;
        }

        $now = CarbonImmutable::now();

        $employeeIds = $this->scopedEmployeeIds();

        $milestonesByDay = $employeeIds === [] || ! $this->showMilestones
            ? new Collection
            : Milestone::query()
                ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status', 'weight_percentage', 'responsible_user_id'])
                ->whereIn('responsible_user_id', $employeeIds)
                ->whereBetween('planned_start_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->with('project:id,name')
                ->withCount(['children', 'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)])
                ->orderBy('planned_start_date')
                ->orderBy('sequence')
                ->get()
                ->groupBy(fn (Milestone $milestone): string => $milestone->planned_start_date->toDateString());

        $tasksByDay = $employeeIds === [] || ! $this->showTasks
            ? new Collection
            : Task::query()
                ->select(['id', 'slug', 'title', 'status', 'due_date', 'created_by'])
                ->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('due_date')
                ->get()
                ->groupBy(fn (Task $task): string => $task->due_date->toDateString());

        $reportUserIds = $employeeIds === []
            ? []
            : User::query()->whereIn('employee_id', $employeeIds)->pluck('id')->all();

        $reportsByDay = $reportUserIds === [] || ! $this->showReports
            ? new Collection
            : Report::query()
                ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date'])
                ->whereIn('user_id', $reportUserIds)
                ->whereBetween('planned_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->with('planIndicator.indicator:id,name')
                ->orderBy('planned_date')
                ->get()
                ->groupBy(fn (Report $report): string => $report->planned_date->toDateString());

        $unscheduledTasks = $employeeIds === [] || ! $this->showTasks
            ? new Collection
            : Task::query()
                ->select(['id', 'slug', 'title', 'status', 'priority', 'due_date', 'created_by'])
                ->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))
                ->whereNull('due_date')
                ->orderBy('title')
                ->get();

        // Proposals, transfers and donors carry no direct "responsible employee"
        // column of their own — the only real ownership signal in the schema is
        // Project::user_id, so anything funding/funded-by one of my projects
        // counts as "mine" here, the same proxy every one of these queries uses.
        // Each of them is money-adjacent, so each is skipped outright unless the
        // viewer holds the matching view permission (see canSeeType()).
        $ownedProjectIds = $employeeIds === []
            ? []
            : Project::query()->whereIn('user_id', $employeeIds)->pluck('id')->all();

        $projectStartsByDay = $employeeIds === [] || ! $this->showProjects
            ? new Collection
            : Project::query()
                ->select(['id', 'name', 'start_date'])
                ->whereIn('user_id', $employeeIds)
                ->whereNotNull('start_date')
                ->whereBetween('start_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('start_date')
                ->get()
                ->groupBy(fn (Project $project): string => $project->start_date->toDateString());

        $projectEndsByDay = $employeeIds === [] || ! $this->showProjects
            ? new Collection
            : Project::query()
                ->select(['id', 'name', 'end_date'])
                ->whereIn('user_id', $employeeIds)
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('end_date')
                ->get()
                ->groupBy(fn (Project $project): string => $project->end_date->toDateString());

        $proposalsByDay = $ownedProjectIds === [] || ! $this->isTypeShown('proposal')
            ? new Collection
            : Proposal::query()
                ->select(['id', 'slug', 'name', 'planned_date_end', 'actual_date_end'])
                ->whereHas('projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->whereBetween('planned_date_end', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('planned_date_end')
                ->get()
                ->groupBy(fn (Proposal $proposal): string => $proposal->planned_date_end->toDateString());

        $transfersByDay = $ownedProjectIds === [] || ! $this->isTypeShown('transfer')
            ? new Collection
            : TransfersTracking::query()
                ->select(['id', 'donor_proposal_id', 'scheduled_date', 'payment_amount', 'amount_transfered_currency', 'ar_status'])
                ->whereNotNull('scheduled_date')
                ->whereBetween('scheduled_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->with('donorProposal:id,slug,donor_id,proposal_id', 'donorProposal.donor:id,name')
                ->orderBy('scheduled_date')
                ->get()
                ->groupBy(fn (TransfersTracking $transfer): string => $transfer->scheduled_date->toDateString());

        $donorFollowUpsByDay = $ownedProjectIds === [] || ! $this->showDonors || ! $this->canViewDonors()
            ? new Collection
            : Donor::query()
                ->select(['id', 'slug', 'name', 'next_step', 'next_step_due_on'])
                ->whereNotNull('next_step_due_on')
                ->whereBetween('next_step_due_on', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->whereHas('proposals.projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->orderBy('next_step_due_on')
                ->get()
                ->groupBy(fn (Donor $donor): string => $donor->next_step_due_on->toDateString());

        $donorReportsByDay = $ownedProjectIds === [] || ! $this->showDonors || ! $this->canViewAgreements()
            ? new Collection
            : DonorReport::query()
                ->select(['id', 'donor_proposal_id', 'title', 'due_on', 'submitted_on'])
                ->whereBetween('due_on', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.id', $ownedProjectIds))
                ->with('donorProposal:id,slug')
                ->orderBy('due_on')
                ->get()
                ->groupBy(fn (DonorReport $report): string => $report->due_on->toDateString());

        return [
            'month' => $month,
            'weeks' => $weeks,
            'days' => $days,
            'milestonesByDay' => $milestonesByDay,
            'tasksByDay' => $tasksByDay,
            'reportsByDay' => $reportsByDay,
            'proposalsByDay' => $proposalsByDay,
            'transfersByDay' => $transfersByDay,
            'donorFollowUpsByDay' => $donorFollowUpsByDay,
            'donorReportsByDay' => $donorReportsByDay,
            'projectStartsByDay' => $projectStartsByDay,
            'projectEndsByDay' => $projectEndsByDay,
            'unscheduledTasks' => $unscheduledTasks,
            'nowOffsetPercent' => ($now->hour * 60 + $now->minute) / (24 * 60) * 100,
        ];
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function parentOptions(): Collection
    {
        if ($this->milestoneProjectId === '') {
            return new Collection;
        }

        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->parentProjectId())
            ->when($invalidParentIds !== [], fn (Builder $query) => $query->whereNotIn('id', $invalidParentIds))
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * The morph keys a task can be raised against, labeled for the source
     * type dropdown.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function sourceTypeOptions(): array
    {
        return [
            'milestone' => __('tasks.source_type_milestone'),
            'proposal' => __('tasks.source_type_proposal'),
            'project' => __('tasks.source_type_project'),
            'program' => __('tasks.source_type_program'),
            'department' => __('tasks.source_type_department'),
        ];
    }

    /**
     * The pickable sources for the currently selected source type.
     *
     * @return Collection<int, Milestone|Proposal|Project|Program|HrDepartment>
     */
    #[Computed]
    public function sourceOptions(): Collection
    {
        return match ($this->taskSourceType) {
            'milestone' => Milestone::query()
                ->select(['id', 'name', 'project_id'])
                ->with('project:id,name')
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'project' => Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
            'program' => Program::options(),
            'department' => HrDepartment::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
            default => Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
        };
    }

    public function updatedMilestoneProjectId(): void
    {
        if ($this->editingMilestoneId === null) {
            $this->parentMilestoneId = '';
        }

        unset($this->parentOptions);
    }

    public function openCreateModal(?string $date = null): void
    {
        Gate::authorize('create', Milestone::class);

        $this->resetMilestoneForm();

        if ($date !== null) {
            $this->plannedStartDate = $date;
        }

        $this->responsibleUserId = (string) ($this->defaultResponsibleEmployeeId() ?? '');
        $this->showMilestoneModal = true;
    }

    public function openEditModal(int $milestoneId): void
    {
        $milestone = Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->findOrFail($milestoneId);

        // Someone who may not edit the milestone is taken to its own page.
        if (Gate::denies('update', $milestone)) {
            $this->redirectRoute('milestones.show', ['milestone' => $milestone], navigate: true);

            return;
        }

        $this->resetValidation();
        $this->editingMilestoneId = $milestone->id;
        $this->name = $milestone->name;
        $this->plannedStartDate = $milestone->planned_start_date->toDateString();
        $this->plannedEndDate = $milestone->planned_end_date?->toDateString() ?? '';
        $this->milestoneStatus = $milestone->status->value;
        $this->sequence = (string) $milestone->sequence;
        $this->weightPercentage = $milestone->weight_percentage;
        $this->responsibleUserId = (string) $milestone->responsible_user_id;
        $this->milestoneProjectId = (string) $milestone->project_id;
        $this->parentMilestoneId = (string) ($milestone->parent_milestone_id ?? '');
        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $this->name = trim($this->name);

        $existingMilestone = $this->editingMilestoneId === null
            ? null
            : Milestone::query()->findOrFail($this->editingMilestoneId);
        $projectId = $existingMilestone?->project_id ?? (int) $this->milestoneProjectId;

        if ($existingMilestone !== null) {
            Gate::authorize('update', $existingMilestone);
        } else {
            Gate::authorize('create', [Milestone::class, $projectId]);
        }
        $invalidParentIds = $this->invalidParentIds();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'plannedStartDate' => ['required', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'sequence' => ['required', 'integer', 'min:0'],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'milestoneProjectId' => [
                'required',
                'integer',
                Rule::exists(Project::class, 'id'),
                Rule::when($existingMilestone !== null, Rule::in([(string) $existingMilestone?->project_id])),
            ],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $projectId),
                ),
                Rule::notIn($invalidParentIds),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'plannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'milestoneProjectId.required' => __('milestones.select_the_project'),
            'milestoneProjectId.in' => __('milestones.a_milestone_cannot_be_moved_to_another_project'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $milestone = $existingMilestone ?? new Milestone;
        $attributes = [
            'name' => $validated['name'],
            'planned_start_date' => $validated['plannedStartDate'],
            'planned_end_date' => $validated['plannedEndDate'] === '' ? null : $validated['plannedEndDate'],
            'status' => $validated['milestoneStatus'],
            'sequence' => (int) $validated['sequence'],
            'weight_percentage' => $validated['weightPercentage'],
            'responsible_user_id' => (int) $validated['responsibleUserId'],
            'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                ? null
                : (int) $validated['parentMilestoneId'],
        ];

        if ($existingMilestone === null) {
            $attributes['project_id'] = (int) $validated['milestoneProjectId'];
        }

        $milestone->fill($attributes)->save();
        $wasRecentlyCreated = $milestone->wasRecentlyCreated;

        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
        unset($this->calendar);

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('milestones.milestone_created') : __('milestones.milestone_updated'),
        );
    }

    public function confirmDelete(int $milestoneId): void
    {
        Gate::authorize('delete', Milestone::query()->findOrFail($milestoneId));

        $this->deletingMilestoneId = $milestoneId;
        $this->showDeleteModal = true;
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        $milestone = Milestone::query()->find($this->deletingMilestoneId);

        if ($milestone !== null) {
            Gate::authorize('delete', $milestone);
        }

        $deleted = $milestone !== null && $milestone->children()->doesntExist() && $milestone->tasks()->withoutGlobalScope(TaskVisibilityScope::class)->doesntExist()
            ? $milestone->delete()
            : false;

        $this->deletingMilestoneId = null;
        $this->showDeleteModal = false;
        unset($this->calendar);

        if (! $deleted) {
            Flux::toast(
                variant: 'danger',
                text: __('milestones.this_milestone_still_has_nested_milestones_or_tasks_move_or'),
            );

            return;
        }

        Flux::toast(variant: 'success', text: __('milestones.milestone_deleted'));
    }

    public function closeMilestoneModal(): void
    {
        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
    }

    protected function resetMilestoneForm(): void
    {
        $this->resetValidation();
        $this->editingMilestoneId = null;
        $this->name = '';
        $this->plannedStartDate = '';
        $this->plannedEndDate = '';
        $this->milestoneStatus = 'planned';
        $this->sequence = '1';
        $this->weightPercentage = '0';
        $this->responsibleUserId = '';
        $this->milestoneProjectId = '';
        $this->parentMilestoneId = '';
        unset($this->parentOptions);
    }

    /** @return array<int, int> */
    protected function invalidParentIds(): array
    {
        if ($this->editingMilestoneId === null) {
            return [];
        }

        $milestone = Milestone::query()
            ->select(['id', 'parent_milestone_id'])
            ->with('childrenRecursive:id,parent_milestone_id,sequence')
            ->find($this->editingMilestoneId);

        if ($milestone === null) {
            return [];
        }

        return [...$milestone->descendants()->modelKeys(), $milestone->id];
    }

    protected function parentProjectId(): int
    {
        if ($this->editingMilestoneId !== null) {
            return (int) Milestone::query()->whereKey($this->editingMilestoneId)->value('project_id');
        }

        return (int) $this->milestoneProjectId;
    }

    /**
     * The Eloquent model class backing the currently selected task source
     * type, for validating taskSourceId exists in the right table.
     *
     * @return class-string<Model>
     */
    protected function sourceModelClass(): string
    {
        return match ($this->taskSourceType) {
            'milestone' => Milestone::class,
            'project' => Project::class,
            'program' => Program::class,
            'department' => HrDepartment::class,
            default => Proposal::class,
        };
    }

    public function updatedTaskSourceType(): void
    {
        $this->taskSourceId = '';
        unset($this->sourceOptions);
    }

    public function openCreateTaskModal(?string $date = null): void
    {
        Gate::authorize('create', Task::class);

        $this->resetTaskForm();

        if ($date !== null) {
            $this->dueDate = $date;
        }

        $defaultEmployeeId = $this->defaultResponsibleEmployeeId();
        $this->assignedTo = $defaultEmployeeId !== null ? [(string) $defaultEmployeeId] : [];
        $this->showTaskModal = true;
    }

    public function openEditTaskModal(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'slug', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->findOrFail($taskId);

        // Someone who may not edit the task (an assignee moves only its
        // status) is taken to the task's own page instead of the edit form.
        if (Gate::denies('update', $task)) {
            $this->redirectRoute('tasks.show', ['task' => $task->slug], navigate: true);

            return;
        }

        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->taskStatus = $task->status->value;
        $this->taskPriority = $task->priority->value;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->completedDate = $task->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $task->progress_percentage;
        $this->assignedTo = $task->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
        $this->taskSourceType = $task->source_type;
        $this->taskSourceId = (string) $task->source_id;
        unset($this->sourceOptions);
        $this->showTaskModal = true;
    }

    public function saveTask(): void
    {
        if ($this->editingTaskId === null) {
            Gate::authorize('create', Task::class);
        } else {
            Gate::authorize('update', Task::query()->findOrFail($this->editingTaskId));
        }

        $this->title = trim($this->title);
        $this->description = trim($this->description);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'taskStatus' => ['required', new Enum(TaskStatus::class)],
            'taskPriority' => ['required', new Enum(TaskPriority::class)],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', Rule::when($this->startDate !== '', ['after_or_equal:startDate'])],
            'completedDate' => ['nullable', 'date'],
            'progressPercentage' => ['required', 'integer', 'min:0', 'max:100'],
            'assignedTo' => ['required', 'array', 'min:1'],
            'assignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
            'taskSourceType' => ['required', 'string', Rule::in(array_keys($this->sourceTypeOptions))],
            'taskSourceId' => [
                'required',
                'integer',
                Rule::exists($this->sourceModelClass(), 'id'),
            ],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'taskSourceType.required' => __('tasks.select_the_source_this_task_is_raised_against'),
            'taskSourceId.required' => __('tasks.select_the_source_this_task_is_raised_against'),
            'taskSourceId.exists' => __('tasks.the_selected_source_is_no_longer_available'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ]);

        $isCreate = $this->editingTaskId === null;
        $task = $isCreate ? new Task : Task::query()->findOrFail($this->editingTaskId);
        $dueDate = $validated['dueDate'] === '' ? null : $validated['dueDate'];

        // Only the task's creator moves its due date, as on the tasks page.
        if (! $isCreate && $task->created_by !== auth()->id() && $dueDate !== $task->due_date?->toDateString()) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));

            $dueDate = $task->due_date?->toDateString();
        }

        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $dueDate,
            'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
            'progress_percentage' => (int) $validated['progressPercentage'],
            'source_type' => $validated['taskSourceType'],
            'source_id' => (int) $validated['taskSourceId'],
        ];

        if ($isCreate) {
            $attributes['created_by'] = (int) auth()->id();
        }

        DB::transaction(function () use ($task, $attributes, $validated): void {
            $task->fill($attributes)->save();

            $employeeIds = collect($validated['assignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

            $task->taskAssignees()->whereNotIn('employee_id', $employeeIds)->delete();
            $existingIds = $task->taskAssignees()->pluck('employee_id');

            $employeeIds->diff($existingIds)->each(fn (int $employeeId) => $task->taskAssignees()->create([
                'employee_id' => $employeeId,
            ]));
        });

        $this->showTaskModal = false;
        $this->resetTaskForm();
        unset($this->calendar);

        Flux::toast(variant: 'success', text: $isCreate ? __('tasks.task_created') : __('tasks.task_updated'));
    }

    public function confirmDeleteTask(int $taskId): void
    {
        Gate::authorize('delete', Task::query()->findOrFail($taskId));

        $this->deletingTaskId = $taskId;
        $this->showTaskDeleteModal = true;
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $taskId = $this->deletingTaskId;
        $deleted = DB::transaction(function () use ($taskId): bool {
            $task = Task::query()->lockForUpdate()->find($taskId);

            if ($task === null || $task->subtasks()->withoutGlobalScope(TaskVisibilityScope::class)->exists()) {
                return false;
            }

            Gate::authorize('delete', $task);

            return (bool) $task->delete();
        });

        $this->deletingTaskId = null;
        $this->showTaskDeleteModal = false;

        if (! $deleted) {
            Flux::toast(variant: 'danger', text: __('tasks.this_task_still_has_subtasks_move_or_delete_them_first'));

            return;
        }

        unset($this->calendar);

        Flux::toast(variant: 'success', text: __('tasks.task_deleted'));
    }

    public function closeTaskModal(): void
    {
        $this->showTaskModal = false;
        $this->resetTaskForm();
    }

    /**
     * Moves a task to a new due date (or clears it, back to "unscheduled")
     * when dropped in the calendar grid or the unscheduled panel.
     */
    public function rescheduleTask(int $taskId, string $date): void
    {
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            return;
        }

        Gate::authorize('update', $task);

        if ($task->created_by !== auth()->id()) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));

            return;
        }

        $task->due_date = $date === '' ? null : CarbonImmutable::parse($date);
        $task->save();

        unset($this->calendar);

        Flux::toast(variant: 'success', text: __('tasks.task_rescheduled'));
    }

    protected function resetTaskForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = TaskStatus::Todo->value;
        $this->taskPriority = TaskPriority::Medium->value;
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->assignedTo = [];
        $this->taskSourceType = 'proposal';
        $this->taskSourceId = '';
        unset($this->sourceOptions);
    }

    /**
     * Flips one type's visibility and, when that leaves exactly one type
     * switched on, jumps the grid to the nearest occurrence of it — so
     * narrowing to a single type never strands the viewer on an empty month.
     */
    /**
     * Flips one type's visibility. The menu stays open across picks — the
     * caller unchecking five of seven boxes to narrow to one type would
     * otherwise have to reopen the menu after every click.
     */
    public function toggleType(string $type): void
    {
        if (! $this->canSeeType($type)) {
            return;
        }

        $property = $this->typeProperty($type);
        $this->$property = ! $this->$property;

        $focused = $this->focusedType();

        if ($focused !== null) {
            $this->jumpToNearest($focused);
        }
    }

    /**
     * The one-click alternative to narrowing by unchecking every other
     * type: switches straight to showing only $type, closes the menu, and
     * jumps to its nearest occurrence.
     */
    public function focusOnlyType(string $type): void
    {
        if (! $this->canSeeType($type)) {
            return;
        }

        $this->showMilestones = $type === 'milestone';
        $this->showTasks = $type === 'task';
        $this->showReports = $type === 'report';
        $this->showProposals = $type === 'proposal';
        $this->showTransfers = $type === 'transfer';
        $this->showDonors = $type === 'donor';
        $this->showProjects = $type === 'project';
        $this->showTypeFilterMenu = false;

        $this->jumpToNearest($type);
    }

    public function resetFilters(): void
    {
        $this->showMilestones = true;
        $this->showTasks = true;
        $this->showReports = true;
        $this->showProposals = true;
        $this->showTransfers = true;
        $this->showDonors = true;
        $this->showProjects = true;
        $this->filterEmployeeIds = [];
    }

    /**
     * Opens the day-detail list for a day that already has at least one
     * visible item on it — the click target that would otherwise land on
     * the "add" context menu instead, since a day with content is something
     * to look at, not a blank slot to fill.
     */
    public function openDayDetail(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $this->dayDetailDate = $date;
        $this->showDayDetailModal = true;
    }

    public function closeDayDetail(): void
    {
        $this->showDayDetailModal = false;
    }

    /**
     * Every item visible on $dayDetailDate, across every type currently
     * shown on the grid — built from the same per-day groupings the chips
     * render from, so this list never disagrees with what the day's chips
     * actually show.
     *
     * @return \Illuminate\Support\Collection<int, array{type: string, kind: string, id: int, url: ?string, title: string, subLabel: ?string}>
     */
    #[Computed]
    public function dayDetailItems(): \Illuminate\Support\Collection
    {
        if ($this->dayDetailDate === '') {
            return new \Illuminate\Support\Collection;
        }

        $calendar = $this->calendar;
        $date = $this->dayDetailDate;
        $rows = new \Illuminate\Support\Collection;

        foreach ($calendar['milestonesByDay']->get($date, new Collection) as $milestone) {
            $rows->push(['type' => 'milestone', 'kind' => 'modal-milestone', 'id' => $milestone->id, 'url' => null, 'title' => $milestone->name, 'subLabel' => $milestone->project?->name]);
        }

        foreach ($calendar['tasksByDay']->get($date, new Collection) as $task) {
            $rows->push(['type' => 'task', 'kind' => 'modal-task', 'id' => $task->id, 'url' => null, 'title' => $task->title, 'subLabel' => null]);
        }

        foreach ($calendar['reportsByDay']->get($date, new Collection) as $report) {
            $rows->push(['type' => 'report', 'kind' => 'link', 'id' => $report->id, 'url' => route('reports.show', $report), 'title' => $report->planIndicator?->indicator?->name ?? __('calendar.report_deadline'), 'subLabel' => null]);
        }

        foreach ($calendar['proposalsByDay']->get($date, new Collection) as $proposal) {
            $rows->push(['type' => 'proposal', 'kind' => 'link', 'id' => $proposal->id, 'url' => route('proposals.show', $proposal), 'title' => $proposal->name, 'subLabel' => null]);
        }

        foreach ($calendar['transfersByDay']->get($date, new Collection) as $transfer) {
            $rows->push([
                'type' => 'transfer',
                'kind' => 'link',
                'id' => $transfer->id,
                'url' => $this->canViewAgreements() ? route('funding-agreements.show', $transfer->donorProposal) : null,
                'title' => $transfer->donorProposal?->donor?->name.' · '.number_format((float) $transfer->payment_amount, 2).' '.$transfer->amount_transfered_currency->value,
                'subLabel' => null,
            ]);
        }

        foreach ($calendar['donorFollowUpsByDay']->get($date, new Collection) as $donor) {
            $rows->push(['type' => 'donor', 'kind' => 'link', 'id' => $donor->id, 'url' => route('donors.show', $donor), 'title' => $donor->name, 'subLabel' => __('calendar.donor_follow_up')]);
        }

        foreach ($calendar['donorReportsByDay']->get($date, new Collection) as $donorReport) {
            $rows->push(['type' => 'donor', 'kind' => 'link', 'id' => $donorReport->id, 'url' => route('funding-agreements.show', $donorReport->donorProposal), 'title' => $donorReport->title, 'subLabel' => __('calendar.donor_report_due')]);
        }

        foreach ($calendar['projectStartsByDay']->get($date, new Collection) as $project) {
            $rows->push(['type' => 'project', 'kind' => 'link', 'id' => $project->id, 'url' => route('projects.show', $project), 'title' => $project->name, 'subLabel' => __('calendar.project_starts')]);
        }

        foreach ($calendar['projectEndsByDay']->get($date, new Collection) as $project) {
            $rows->push(['type' => 'project', 'kind' => 'link', 'id' => $project->id, 'url' => route('projects.show', $project), 'title' => $project->name, 'subLabel' => __('calendar.project_ends')]);
        }

        return $rows;
    }

    /**
     * Whether the viewer may see proposal deadlines and the proposals behind them.
     */
    protected function canViewProposals(): bool
    {
        return auth()->user()?->can('proposals.view') ?? false;
    }

    /**
     * Whether the viewer may see transfer dates and the amounts on them.
     */
    protected function canViewTransfers(): bool
    {
        return auth()->user()?->can('transfers.view') ?? false;
    }

    /**
     * Whether the viewer may see donors and their follow-up dates.
     */
    protected function canViewDonors(): bool
    {
        return auth()->user()?->can('donors.view') ?? false;
    }

    /**
     * Whether the viewer may see funding agreements, and so donor reports and
     * the agreement page a transfer or donor report links to.
     */
    protected function canViewAgreements(): bool
    {
        return auth()->user()?->can('funding-agreements.view') ?? false;
    }

    /**
     * Whether the viewer may see any occurrence of $type. The 'donor' type
     * covers follow-ups (donors.view) and donor reports (funding-agreements.view),
     * so it is open when either half is; each half is still gated on its own.
     */
    protected function canSeeType(string $type): bool
    {
        return match ($type) {
            'proposal' => $this->canViewProposals(),
            'transfer' => $this->canViewTransfers(),
            'donor' => $this->canViewDonors() || $this->canViewAgreements(),
            default => true,
        };
    }

    /**
     * Whether $type is switched on AND permitted. The show* toggles are public
     * Livewire state a browser can set, so they never decide visibility alone.
     */
    protected function isTypeShown(string $type): bool
    {
        return $this->canSeeType($type) && $this->{$this->typeProperty($type)};
    }

    protected function typeProperty(string $type): string
    {
        return match ($type) {
            'milestone' => 'showMilestones',
            'task' => 'showTasks',
            'report' => 'showReports',
            'proposal' => 'showProposals',
            'transfer' => 'showTransfers',
            'donor' => 'showDonors',
            'project' => 'showProjects',
        };
    }

    /**
     * The single type key ('milestone', 'task', …) when exactly one of the
     * seven show* toggles is on, otherwise null. Drives the "focused" banner,
     * grid highlighting and related-dates panel — all three stay off while
     * more than one type (or none) is visible, since "filtered to one thing"
     * only means something when it is actually just one thing.
     */
    protected function focusedType(): ?string
    {
        $active = array_filter([
            'milestone' => $this->isTypeShown('milestone'),
            'task' => $this->isTypeShown('task'),
            'report' => $this->isTypeShown('report'),
            'proposal' => $this->isTypeShown('proposal'),
            'transfer' => $this->isTypeShown('transfer'),
            'donor' => $this->isTypeShown('donor'),
            'project' => $this->isTypeShown('project'),
        ]);

        return count($active) === 1 ? array_key_first($active) : null;
    }

    /**
     * Moves the grid to the month (or period anchor) containing the nearest
     * occurrence of $type: the next upcoming one if there is one, otherwise
     * the most recent past one, so focusing a type with only historical
     * records still lands somewhere with data instead of today's empty month.
     */
    protected function jumpToNearest(string $type): void
    {
        $nearest = $this->nearestDateFor($type);

        if ($nearest === null) {
            return;
        }

        if ($this->viewMode === 'month') {
            $this->month = $nearest->format('Y-m');
        } else {
            $this->anchorDate = $nearest->toDateString();
        }
    }

    protected function nearestDateFor(string $type): ?CarbonImmutable
    {
        $employeeIds = $this->scopedEmployeeIds();

        if ($employeeIds === []) {
            return null;
        }

        return match ($type) {
            'milestone' => $this->nearestOrLatestDate(
                Milestone::query()->whereIn('responsible_user_id', $employeeIds),
                'planned_start_date'
            ),
            'task' => $this->nearestOrLatestDate(
                Task::query()->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))->whereNotNull('due_date'),
                'due_date'
            ),
            'report' => $this->nearestOrLatestDate(
                Report::query()->whereIn('user_id', User::query()->whereIn('employee_id', $employeeIds)->pluck('id')),
                'planned_date'
            ),
            'proposal' => ! $this->canViewProposals() ? null : $this->nearestOrLatestDate(
                Proposal::query()->whereHas('projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'planned_date_end'
            ),
            'transfer' => ! $this->canViewTransfers() ? null : $this->nearestOrLatestDate(
                TransfersTracking::query()->whereNotNull('scheduled_date')->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'scheduled_date'
            ),
            'donor' => $this->pickCloserDate(
                ! $this->canViewDonors() ? null : $this->nearestOrLatestDate(
                    Donor::query()->whereNotNull('next_step_due_on')->whereHas('proposals.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                    'next_step_due_on'
                ),
                ! $this->canViewAgreements() ? null : $this->nearestOrLatestDate(
                    DonorReport::query()->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                    'due_on'
                ),
            ),
            'project' => $this->pickCloserDate(
                $this->nearestOrLatestDate(
                    Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('start_date'),
                    'start_date'
                ),
                $this->nearestOrLatestDate(
                    Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('end_date'),
                    'end_date'
                ),
            ),
            default => null,
        };
    }

    /**
     * The next date on/after today for $column, or the most recent one
     * before today when nothing upcoming exists.
     */
    protected function nearestOrLatestDate(Builder $query, string $column): ?CarbonImmutable
    {
        $today = CarbonImmutable::today()->toDateString();

        $upcoming = (clone $query)->where($column, '>=', $today)->orderBy($column)->value($column);

        if ($upcoming !== null) {
            return CarbonImmutable::parse($upcoming);
        }

        $past = (clone $query)->where($column, '<', $today)->orderByDesc($column)->value($column);

        return $past === null ? null : CarbonImmutable::parse($past);
    }

    /**
     * Picks whichever of two candidate dates sits fewer days from today, so
     * a composite type (donor follow-ups + donor reports, project starts +
     * ends) jumps to whichever of its two sources is actually the nearer one.
     */
    protected function pickCloserDate(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        $today = CarbonImmutable::today();

        return abs($today->diffInDays($a, false)) <= abs($today->diffInDays($b, false)) ? $a : $b;
    }

    /**
     * Up to $limit upcoming records for $column (today or later), or, when
     * none are upcoming, the $limit most recent past ones in chronological
     * order — so a focused type with only historical records still shows a
     * populated related-dates list instead of an empty one.
     *
     * @return Collection<int, Model>
     */
    protected function upcomingOrRecent(Builder $query, string $column, int $limit = 15): Collection
    {
        $today = CarbonImmutable::today()->toDateString();

        $upcoming = (clone $query)->where($column, '>=', $today)->orderBy($column)->limit($limit)->get();

        if ($upcoming->isNotEmpty()) {
            return $upcoming;
        }

        return (clone $query)->where($column, '<', $today)->orderByDesc($column)->limit($limit)->get()->reverse()->values();
    }

    /**
     * The related-dates list for the currently focused type: title, date,
     * an optional sub-label (which of a composite type's two sources this
     * row came from) and how to open it — a modal action for milestones and
     * tasks (they have no show page of their own), a link for everything
     * else, matching the same click-through the grid chips use.
     *
     * Returns the base Support Collection, not the Eloquent one aliased as
     * Collection in this file: mapping models to plain arrays makes Eloquent
     * Collection::map() downgrade to toBase() internally, so the real return
     * value never satisfies an Eloquent-typed signature.
     *
     * @return \Illuminate\Support\Collection<int, array{kind: string, id: int, url: ?string, title: string, subLabel: ?string, date: CarbonImmutable}>
     */
    #[Computed]
    public function focusedItems(): \Illuminate\Support\Collection
    {
        $type = $this->focusedType();

        if ($type === null) {
            return new \Illuminate\Support\Collection;
        }

        $employeeIds = $this->scopedEmployeeIds();

        if ($employeeIds === []) {
            return new \Illuminate\Support\Collection;
        }

        return match ($type) {
            'milestone' => $this->upcomingOrRecent(
                Milestone::query()->whereIn('responsible_user_id', $employeeIds)->with('project:id,name'),
                'planned_start_date'
            )->map(fn (Milestone $milestone): array => [
                'kind' => 'modal-milestone',
                'id' => $milestone->id,
                'url' => null,
                'title' => $milestone->name,
                'subLabel' => $milestone->project?->name,
                'date' => $milestone->planned_start_date,
            ]),
            'task' => $this->upcomingOrRecent(
                Task::query()->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))->whereNotNull('due_date'),
                'due_date'
            )->map(fn (Task $task): array => [
                'kind' => 'modal-task',
                'id' => $task->id,
                'url' => null,
                'title' => $task->title,
                'subLabel' => null,
                'date' => $task->due_date,
            ]),
            'report' => $this->upcomingOrRecent(
                Report::query()->whereIn('user_id', User::query()->whereIn('employee_id', $employeeIds)->pluck('id'))->with('planIndicator.indicator:id,name'),
                'planned_date'
            )->map(fn (Report $report): array => [
                'kind' => 'link',
                'id' => $report->id,
                'url' => route('reports.show', $report),
                'title' => $report->planIndicator?->indicator?->name ?? __('calendar.report_deadline'),
                'subLabel' => null,
                'date' => $report->planned_date,
            ]),
            'proposal' => ($this->canViewProposals() ? $this->upcomingOrRecent(
                Proposal::query()->whereHas('projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'planned_date_end'
            ) : new Collection)->map(fn (Proposal $proposal): array => [
                'kind' => 'link',
                'id' => $proposal->id,
                'url' => route('proposals.show', $proposal),
                'title' => $proposal->name,
                'subLabel' => null,
                'date' => $proposal->planned_date_end,
            ]),
            'transfer' => ($this->canViewTransfers() ? $this->upcomingOrRecent(
                TransfersTracking::query()
                    ->whereNotNull('scheduled_date')
                    ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds))
                    ->with('donorProposal:id,slug,donor_id', 'donorProposal.donor:id,name'),
                'scheduled_date'
            ) : new Collection)->map(fn (TransfersTracking $transfer): array => [
                'kind' => 'link',
                'id' => $transfer->id,
                'url' => $this->canViewAgreements() ? route('funding-agreements.show', $transfer->donorProposal) : null,
                'title' => $transfer->donorProposal?->donor?->name.' · '.number_format((float) $transfer->payment_amount, 2).' '.$transfer->amount_transfered_currency->value,
                'subLabel' => null,
                'date' => $transfer->scheduled_date,
            ]),
            'donor' => ($this->canViewDonors() ? $this->upcomingOrRecent(
                Donor::query()->whereNotNull('next_step_due_on')->whereHas('proposals.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'next_step_due_on'
            ) : new Collection)->map(fn (Donor $donor): array => [
                'kind' => 'link',
                'id' => $donor->id,
                'url' => route('donors.show', $donor),
                'title' => $donor->name,
                'subLabel' => __('calendar.donor_follow_up'),
                'date' => $donor->next_step_due_on,
            ])->concat(
                ($this->canViewAgreements() ? $this->upcomingOrRecent(
                    DonorReport::query()
                        ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds))
                        ->with('donorProposal:id,slug'),
                    'due_on'
                ) : new Collection)->map(fn (DonorReport $report): array => [
                    'kind' => 'link',
                    'id' => $report->id,
                    'url' => route('funding-agreements.show', $report->donorProposal),
                    'title' => $report->title,
                    'subLabel' => __('calendar.donor_report_due'),
                    'date' => $report->due_on,
                ])
            )->sortBy('date')->values()->take(15),
            'project' => $this->upcomingOrRecent(
                Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('start_date'),
                'start_date'
            )->map(fn (Project $project): array => [
                'kind' => 'link',
                'id' => $project->id,
                'url' => route('projects.show', $project),
                'title' => $project->name,
                'subLabel' => __('calendar.project_starts'),
                'date' => $project->start_date,
            ])->concat(
                $this->upcomingOrRecent(
                    Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('end_date'),
                    'end_date'
                )->map(fn (Project $project): array => [
                    'kind' => 'link',
                    'id' => $project->id,
                    'url' => route('projects.show', $project),
                    'title' => $project->name,
                    'subLabel' => __('calendar.project_ends'),
                    'date' => $project->end_date,
                ])
            )->sortBy('date')->values()->take(15),
            default => new \Illuminate\Support\Collection,
        };
    }
};
?>

@php
    $calendar = $this->calendar;
    $today = \Carbon\CarbonImmutable::today();
    $hasEmployee = auth()->user()->employee_id !== null;

    $markerTone = function (Milestone $milestone): string {
        if ($milestone->isMissed() || $milestone->isOverdue()) {
            return 'bg-status-critical-soft text-primary dark:text-brand-text';
        }

        return match ($milestone->status) {
            MilestoneStatus::Reached => 'bg-status-good-soft text-status-good',
            MilestoneStatus::Ongoing => 'bg-status-warn-soft text-status-warn',
            MilestoneStatus::Postponed, MilestoneStatus::Cancelled => 'bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted',
            MilestoneStatus::Planned => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-surface-1 dark:text-ink dark:ring-line',
        };
    };

    $taskTone = fn (TaskStatus $status): string => match ($status) {
        TaskStatus::Done => 'bg-status-good-soft text-status-good',
        TaskStatus::InProgress, TaskStatus::UnderReview => 'bg-funding-soft text-funding',
        TaskStatus::Todo => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-surface-1 dark:text-ink dark:ring-line',
    };

    $reportTone = fn (ReportStatus $status): string => match ($status) {
        ReportStatus::Approved => 'bg-status-good-soft text-status-good',
        ReportStatus::Reviewed => 'bg-funding-soft text-funding',
        ReportStatus::Draft => 'bg-status-warn-soft text-status-warn',
    };

    $proposalTone = function (\App\Models\Proposal $proposal) use ($today): string {
        if ($proposal->actual_date_end !== null) {
            return 'bg-status-good-soft text-status-good';
        }

        if ($proposal->planned_date_end->isBefore($today)) {
            return 'bg-status-critical-soft text-primary dark:text-brand-text';
        }

        return 'bg-status-active-soft text-status-active';
    };

    $transferTone = fn (ArStatus $status): string => match ($status) {
        ArStatus::Received => 'bg-status-good-soft text-status-good',
        ArStatus::Failed => 'bg-status-critical-soft text-primary dark:text-brand-text',
        ArStatus::Pending => 'bg-status-active-soft text-status-active',
    };

    $donorFollowUpTone = function (\App\Models\Donor $donor): string {
        return $donor->isFollowUpOverdue()
            ? 'bg-status-critical-soft text-primary dark:text-brand-text'
            : 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';
    };

    $donorReportTone = function (\App\Models\DonorReport $report): string {
        if ($report->isSubmitted()) {
            return 'bg-status-good-soft text-status-good';
        }

        return $report->isOverdue()
            ? 'bg-status-critical-soft text-primary dark:text-brand-text'
            : 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';
    };

    $projectTone = 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';

    // A dot in front of each chip carries the item TYPE, independent of the
    // tone above (which carries its STATUS) — so a Reached milestone and a
    // Done task both render green fills but stay visually distinguishable.
    $typeDotClasses = [
        'milestone' => 'bg-funding',
        'task' => 'bg-status-warn',
        'report' => 'bg-primary dark:bg-status-critical',
        'proposal' => 'bg-status-active',
        'transfer' => 'bg-status-good',
        'donor' => 'bg-secondary-dark dark:bg-ink-muted',
        'project' => 'bg-secondary-medium',
    ];

    // Literal ring-*/text-* utilities kept as their own arrays (rather than
    // derived from $typeDotClasses with str_replace) because Tailwind only
    // generates a class it can find as a literal token while scanning this
    // file — a runtime-built "ring-status-active" string would never compile.
    $typeRingClasses = [
        'milestone' => 'ring-funding',
        'task' => 'ring-status-warn',
        'report' => 'ring-primary',
        'proposal' => 'ring-status-active',
        'transfer' => 'ring-status-good',
        'donor' => 'ring-secondary-dark dark:ring-ink-muted',
        'project' => 'ring-secondary-medium',
    ];

    $typeTextClasses = [
        'milestone' => 'text-funding',
        'task' => 'text-status-warn',
        'report' => 'text-primary dark:text-brand-text',
        'proposal' => 'text-status-active',
        'transfer' => 'text-status-good',
        'donor' => 'text-secondary-dark dark:text-ink-faint',
        'project' => 'text-secondary-medium',
    ];

    $typeLabels = [
        'milestone' => __('tasks.milestone'),
        'task' => __('tasks.task'),
        'report' => __('calendar.report_deadline'),
        'proposal' => __('calendar.proposal_deadline'),
        'transfer' => __('calendar.transfer'),
        'donor' => __('calendar.donor_follow_up'),
        'project' => __('calendar.project_timeline'),
    ];

    // Which per-day grouping(s) in $calendar back each type, so the grid can
    // tell whether a given day carries the focused type without re-querying.
    // Donor and project are composite (two source columns each), same as
    // focusedItems() above merges two queries for them.
    $focusedGroupKeys = [
        'milestone' => ['milestonesByDay'],
        'task' => ['tasksByDay'],
        'report' => ['reportsByDay'],
        'proposal' => ['proposalsByDay'],
        'transfer' => ['transfersByDay'],
        'donor' => ['donorFollowUpsByDay', 'donorReportsByDay'],
        'project' => ['projectStartsByDay', 'projectEndsByDay'],
    ];

    // Every per-day grouping, regardless of type — used to tell whether a
    // day has anything on it at all, so its content area can open the
    // day-detail list instead of the "add" menu.
    $allGroupKeys = ['milestonesByDay', 'tasksByDay', 'reportsByDay', 'proposalsByDay', 'transfersByDay', 'donorFollowUpsByDay', 'donorReportsByDay', 'projectStartsByDay', 'projectEndsByDay'];

    $focusedType = $this->focusedType();

    $selectedFilterEmployees = $filterEmployeeIds === [] ? new \Illuminate\Support\Collection : $this->userOptions->whereIn('id', array_map('intval', $filterEmployeeIds))->values();

    $periodHeading = match ($viewMode) {
        'month' => $calendar['month']->translatedFormat(__('formats.month_year')),
        'day' => $calendar['days'][0]->translatedFormat('l, F j'),
        default => $calendar['days'][0]->translatedFormat('F j').' - '.end($calendar['days'])->translatedFormat('F j'),
    };
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="calendar-heading">
    <x-ui.page-header heading-id="calendar-heading" :title="__('common.my_calendar')" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button size="sm" wire:click="goToToday" data-test="calendar-today-button">{{ __('common.today') }}</x-ui.button>

            <flux:dropdown position="bottom-start" class="filter-picker">
                <x-ui.button size="sm" icon:trailing="chevron-down" data-test="calendar-view-mode-button">{{ __('calendar.'.$viewMode) }}</x-ui.button>
                <flux:menu class="filter-menu">
                    <div class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-ink-muted">{{ __('calendar.time_period') }}</div>
                    @foreach ([['day', 'D'], ['four_days', '4'], ['week', 'W'], ['month', 'M']] as [$periodKey, $shortcut])
                        <flux:menu.item wire:click="setViewMode('{{ $periodKey }}')" data-test="calendar-view-mode-{{ $periodKey }}">
                            <span class="min-w-0 flex-1 truncate">{{ __('calendar.'.$periodKey) }}</span>
                            <kbd class="me-1 rounded border border-secondary-soft px-1.5 py-0.5 text-[10px] font-semibold dark:border-line">{{ $shortcut }}</kbd>
                            @if ($viewMode === $periodKey)<flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <div class="flex items-center gap-1">
                <x-ui.button size="sm" wire:click="{{ $viewMode === 'month' ? 'previousMonth' : 'previousPeriod' }}" :aria-label="__('common.previous')">
                    <flux:icon.chevron-left variant="micro" class="size-4 rtl:-scale-x-100" />
                </x-ui.button>
                <x-ui.button size="sm" wire:click="{{ $viewMode === 'month' ? 'nextMonth' : 'nextPeriod' }}" :aria-label="__('common.next')">
                    <flux:icon.chevron-right variant="micro" class="size-4 rtl:-scale-x-100" />
                </x-ui.button>
            </div>
            <flux:heading size="lg">{{ $periodHeading }}</flux:heading>
        </div>

        <div class="flex items-center gap-3">
            <div class="inline-flex items-center rounded-lg border border-secondary-light bg-white dark:border-field-line dark:bg-surface-1">
            <div class="filter-picker relative">
                <button
                    type="button"
                    data-flux-button
                    wire:click="$toggle('showTypeFilterMenu')"
                    aria-expanded="{{ $showTypeFilterMenu ? 'true' : 'false' }}"
                    aria-haspopup="listbox"
                    aria-label="{{ __('calendar.filter_by_type') }}"
                    data-test="calendar-type-filter-button"
                    class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-s-lg px-2.5 py-1.5 text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2"
                >
                    <flux:icon.funnel variant="micro" class="size-4" />
                    <span class="hidden text-sm sm:inline">{{ __('calendar.filter_by_type') }}</span>
                </button>

                @if ($showTypeFilterMenu)
                    <div
                        x-on:click.outside="$wire.showTypeFilterMenu = false"
                        x-on:keydown.escape.window="$wire.showTypeFilterMenu = false"
                        data-flux-menu
                        role="listbox"
                        aria-multiselectable="true"
                        class="filter-menu absolute end-0 z-30 mt-1 min-w-44 rounded-lg border p-[.3125rem] shadow-xs"
                    >
                        @foreach ([
                            ['milestone', 'showMilestones', 'tasks.milestone', 'calendar-filter-milestones'],
                            ['task', 'showTasks', 'tasks.task', 'calendar-filter-tasks'],
                            ['report', 'showReports', 'calendar.report_deadline', 'calendar-filter-reports'],
                        ] as [$typeKey, $showProp, $labelKey, $testHook])
                            <div class="group flex items-center gap-1 rounded-md hover:bg-secondary-soft dark:hover:bg-white/[0.06]" role="option" aria-selected="{{ $$showProp ? 'true' : 'false' }}">
                                <button type="button" data-flux-menu-item wire:click="toggleType('{{ $typeKey }}')" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 px-2 py-1.5 text-start text-sm" data-test="{{ $testHook }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses[$typeKey] }}"></span>
                                    <span class="min-w-0 flex-1 truncate">{{ __($labelKey) }}</span>
                                    @if ($$showProp)<flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                                </button>
                                <button type="button" wire:click="focusOnlyType('{{ $typeKey }}')" class="me-1 shrink-0 cursor-pointer rounded px-1.5 py-1 text-[10px] font-semibold text-secondary-medium opacity-0 hover:bg-white group-hover:opacity-100 dark:text-ink-muted dark:hover:bg-white/[0.06]" data-test="calendar-filter-only-{{ $typeKey }}">
                                    {{ __('calendar.only') }}
                                </button>
                            </div>
                        @endforeach

                        <div class="my-1 border-t border-secondary-soft dark:border-line"></div>

                        @foreach ([
                            ['proposal', 'showProposals', 'calendar.proposal_deadline', 'calendar-filter-proposals'],
                            ['transfer', 'showTransfers', 'calendar.transfer', 'calendar-filter-transfers'],
                            ['donor', 'showDonors', 'calendar.donor_follow_up', 'calendar-filter-donors'],
                            ['project', 'showProjects', 'calendar.project_timeline', 'calendar-filter-projects'],
                        ] as [$typeKey, $showProp, $labelKey, $testHook])
                            @continue(! $this->canSeeType($typeKey))
                            <div class="group flex items-center gap-1 rounded-md hover:bg-secondary-soft dark:hover:bg-white/[0.06]" role="option" aria-selected="{{ $$showProp ? 'true' : 'false' }}">
                                <button type="button" data-flux-menu-item wire:click="toggleType('{{ $typeKey }}')" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 px-2 py-1.5 text-start text-sm" data-test="{{ $testHook }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses[$typeKey] }}"></span>
                                    <span class="min-w-0 flex-1 truncate">{{ __($labelKey) }}</span>
                                    @if ($$showProp)<flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                                </button>
                                <button type="button" wire:click="focusOnlyType('{{ $typeKey }}')" class="me-1 shrink-0 cursor-pointer rounded px-1.5 py-1 text-[10px] font-semibold text-secondary-medium opacity-0 hover:bg-white group-hover:opacity-100 dark:text-ink-muted dark:hover:bg-white/[0.06]" data-test="calendar-filter-only-{{ $typeKey }}">
                                    {{ __('calendar.only') }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="h-5 w-px shrink-0 bg-secondary-light dark:bg-line"></div>

            <x-ui.multi-select-popover
                :label="__('calendar.filter_by_responsible')"
                class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-e-lg px-2.5 py-1.5 text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2"
                data-test="calendar-responsible-filter-button"
            >
                <x-slot:trigger>
                    <flux:icon.user variant="micro" class="size-4" />
                    <span class="hidden text-sm sm:inline">{{ __('calendar.filter_by_responsible') }}</span>
                    @if ($selectedFilterEmployees->isNotEmpty())
                        <span class="inline-flex min-w-4 items-center justify-center rounded-full bg-primary dark:bg-white/10 dark:text-ink px-1 text-[10px] font-semibold text-white" data-test="calendar-responsible-filter-count">{{ $selectedFilterEmployees->count() }}</span>
                    @endif
                </x-slot:trigger>

                <button type="button" wire:click="$set('filterEmployeeIds', [])" class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]" data-test="calendar-responsible-filter-me">
                    <span class="min-w-0 flex-1 truncate">{{ __('calendar.me') }}</span>
                    @if ($filterEmployeeIds === [])<flux:icon.check class="ms-auto size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                </button>
                <div class="my-1 border-t border-secondary-soft dark:border-line"></div>
                @foreach ($this->userOptions as $userOption)
                    <label
                        x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                        data-test="calendar-responsible-filter-option-{{ $userOption->id }}"
                    >
                        <input type="checkbox" wire:model.live="filterEmployeeIds" value="{{ $userOption->id }}" class="rounded border-secondary-light text-primary dark:text-brand-text focus:ring-primary" />
                        <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                    </label>
                @endforeach
            </x-ui.multi-select-popover>
            </div>

            <flux:dropdown position="bottom-end" class="filter-picker">
                <x-ui.button tone="primary" icon="plus" icon:trailing="chevron-down" data-test="calendar-add-button">{{ __('common.add') }}</x-ui.button>
                <flux:menu class="filter-menu">
                    @can('create', App\Models\Milestone::class)
                        <flux:menu.item wire:click="openCreateModal" data-test="calendar-add-milestone-button">
                            <flux:icon.flag variant="micro" class="size-4" />
                            {{ __('milestones.add_milestone') }}
                        </flux:menu.item>
                    @endcan
                    @can('create', App\Models\Task::class)
                        <flux:menu.item wire:click="openCreateTaskModal" data-test="calendar-add-task-button">
                            <flux:icon.clipboard-document-check variant="micro" class="size-4" />
                            {{ __('tasks.add_task') }}
                        </flux:menu.item>
                    @endcan
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if (! $hasEmployee)
        <x-ui.panel>
            <x-ui.empty-state icon="user" :heading="__('calendar.no_linked_employee')" :body="__('calendar.link_your_account_to_see_your_calendar')" />
        </x-ui.panel>
    @else
        @if ($focusedType !== null)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-secondary-soft bg-secondary-soft/30 px-4 py-3 dark:border-line dark:bg-white/[0.04]" data-test="calendar-focused-banner">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-secondary-soft dark:bg-surface-1 dark:ring-line">
                        <span class="size-2.5 rounded-full {{ $typeDotClasses[$focusedType] }}"></span>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('calendar.showing_type', ['type' => $typeLabels[$focusedType]]) }}</p>
                        <p class="text-xs text-secondary-medium dark:text-ink-muted">{{ trans_choice('calendar.items_count', $this->focusedItems->count(), ['count' => $this->focusedItems->count()]) }}</p>
                    </div>
                </div>
                <x-ui.button size="sm" wire:click="resetFilters" data-test="calendar-clear-focus">{{ __('calendar.clear_filter') }}</x-ui.button>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-4 text-xs text-secondary-medium dark:text-ink-muted">
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['milestone'] }}"></span>{{ __('tasks.milestone') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['task'] }}"></span>{{ __('tasks.task') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['report'] }}"></span>{{ __('calendar.report_deadline') }}</span>
                @if ($this->canSeeType('proposal'))
                    <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['proposal'] }}"></span>{{ __('calendar.proposal_deadline') }}</span>
                @endif
                @if ($this->canSeeType('transfer'))
                    <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['transfer'] }}"></span>{{ __('calendar.transfer') }}</span>
                @endif
                @if ($this->canSeeType('donor'))
                    <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['donor'] }}"></span>{{ __('calendar.donor_follow_up') }}</span>
                @endif
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['project'] }}"></span>{{ __('calendar.project_timeline') }}</span>
            </div>
        @endif

        <div class="grid gap-4 @6xl/directory:grid-cols-[minmax(0,1fr)_16rem]">
        <div
            class="relative overflow-hidden rounded-xl border border-secondary-soft dark:border-line"
            x-data="{ menuOpen: false, menuX: 0, menuY: 0, menuDate: '' }"
            x-on:click.away="menuOpen = false"
            x-on:keydown.escape.window="menuOpen = false"
        >
            @if ($viewMode === 'month')
                <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-xs font-semibold uppercase tracking-wide text-secondary-medium dark:border-line dark:bg-surface-2 dark:text-ink-muted">
                    @foreach ($calendar['weeks'][0] as $weekdayHeader)
                        <div class="py-2">{{ $weekdayHeader->translatedFormat('D') }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7">
                    @foreach ($calendar['weeks'] as $week)
                        @foreach ($week as $day)
                            @php
                                $isCurrentMonth = $day->month === $calendar['month']->month;
                                $isToday = $day->isSameDay($today);
                                $dayFocusedCount = $focusedType === null ? 0 : collect($focusedGroupKeys[$focusedType])->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                                $dayItemCount = collect($allGroupKeys)->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                            @endphp
                            <div
                                wire:key="calendar-day-{{ $day->toDateString() }}"
                                x-on:click="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                                x-on:contextmenu.prevent="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                                @class([
                                    'flex min-h-28 cursor-pointer flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start last:border-e-0 [&:nth-child(7n)]:border-e-0 hover:bg-secondary-soft/40 dark:border-line dark:hover:bg-surface-2 transition-opacity',
                                    'opacity-40' => ! $isCurrentMonth,
                                    'opacity-50' => $focusedType !== null && $dayFocusedCount === 0,
                                    'ring-2 ring-inset '.$typeRingClasses[$focusedType ?? 'milestone'] => $focusedType !== null && $dayFocusedCount > 0,
                                ])
                                data-test="calendar-day-{{ $day->toDateString() }}"
                            >
                                <span @class([
                                    'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                    'bg-primary dark:bg-ink dark:text-surface-0 text-white' => $isToday,
                                    'text-secondary-dark dark:text-ink' => ! $isToday,
                                ])>
                                    {{ $day->day }}
                                </span>

                                <div
                                    class="flex min-h-4 flex-1 flex-col gap-1"
                                    x-data="taskDropList('my-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $day->toDateString() }}'))"
                                    @if ($dayItemCount > 0)
                                        wire:click.stop="openDayDetail('{{ $day->toDateString() }}')"
                                        role="button"
                                        tabindex="0"
                                        data-test="calendar-day-content-{{ $day->toDateString() }}"
                                    @endif
                                >
                                    @include('pages.calendar._item-chips', ['day' => $day, 'calendar' => $calendar, 'markerTone' => $markerTone, 'taskTone' => $taskTone, 'reportTone' => $reportTone, 'proposalTone' => $proposalTone, 'transferTone' => $transferTone, 'donorFollowUpTone' => $donorFollowUpTone, 'donorReportTone' => $donorReportTone, 'projectTone' => $projectTone, 'typeDotClasses' => $typeDotClasses])
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            @else
                @php
                    $periodDays = $calendar['days'];
                    $now = \Carbon\CarbonImmutable::now();
                    $currentHour = $now->hour;
                    $currentMinuteFraction = $now->minute / 60;
                    $gridMinWidth = 4.5 + count($periodDays) * 6;
                    $gridColumns = 'grid-template-columns: 4.5rem repeat('.count($periodDays).', minmax(0, 1fr)); min-width: '.$gridMinWidth.'rem;';
                @endphp

                <div class="overflow-x-auto">
                <div class="grid" style="{{ $gridColumns }}">
                    <div class="border-b border-e border-secondary-soft dark:border-line"></div>
                    @foreach ($periodDays as $day)
                        @php $isToday = $day->isSameDay($today); @endphp
                        <div class="border-b border-e border-secondary-soft p-2 text-center last:border-e-0 dark:border-line">
                            <div @class(['text-sm font-semibold', 'text-primary dark:text-brand-text' => $isToday, 'text-secondary-dark dark:text-ink' => ! $isToday])>{{ $day->translatedFormat('l') }}</div>
                            <div @class(['text-xs', 'text-primary dark:text-brand-text' => $isToday, 'text-secondary-medium dark:text-ink-muted' => ! $isToday])>{{ $day->translatedFormat('j M') }}</div>
                        </div>
                    @endforeach

                    <div class="flex items-center justify-end border-e border-secondary-soft px-2 py-1.5 text-[11px] font-semibold text-secondary-medium dark:border-line dark:text-ink-muted">{{ __('calendar.all_day') }}</div>
                    @foreach ($periodDays as $day)
                        @php
                            $dayFocusedCount = $focusedType === null ? 0 : collect($focusedGroupKeys[$focusedType])->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                            $dayItemCount = collect($allGroupKeys)->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                        @endphp
                        <div
                            wire:key="calendar-allday-{{ $day->toDateString() }}"
                            x-on:click="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                            x-on:contextmenu.prevent="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                            @class([
                                'flex min-h-10 cursor-pointer flex-col gap-1 border-e border-secondary-soft p-1.5 text-start last:border-e-0 hover:bg-secondary-soft/40 dark:border-line dark:hover:bg-surface-2 transition-opacity',
                                'opacity-50' => $focusedType !== null && $dayFocusedCount === 0,
                                'ring-2 ring-inset '.$typeRingClasses[$focusedType ?? 'milestone'] => $focusedType !== null && $dayFocusedCount > 0,
                            ])
                            data-test="calendar-allday-{{ $day->toDateString() }}"
                        >
                            <div
                                class="flex min-h-4 flex-1 flex-col gap-1"
                                x-data="taskDropList('my-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $day->toDateString() }}'))"
                                @if ($dayItemCount > 0)
                                    wire:click.stop="openDayDetail('{{ $day->toDateString() }}')"
                                    role="button"
                                    tabindex="0"
                                    data-test="calendar-day-content-{{ $day->toDateString() }}"
                                @endif
                            >
                                @include('pages.calendar._item-chips', ['day' => $day, 'calendar' => $calendar, 'markerTone' => $markerTone, 'taskTone' => $taskTone, 'reportTone' => $reportTone, 'proposalTone' => $proposalTone, 'transferTone' => $transferTone, 'donorFollowUpTone' => $donorFollowUpTone, 'donorReportTone' => $donorReportTone, 'projectTone' => $projectTone, 'typeDotClasses' => $typeDotClasses])
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="max-h-96 overflow-y-auto border-t border-secondary-soft dark:border-line" x-init="$el.scrollTop = Math.max(0, {{ $currentHour }} * 48 - 160)">
                    <div class="grid" style="{{ $gridColumns }}">
                        @for ($hour = 0; $hour < 24; $hour++)
                            <div class="h-12 border-b border-e border-secondary-soft px-2 py-1 text-end text-[11px] text-secondary-medium dark:border-line dark:text-ink-muted">
                                {{ $hour === 0 ? '' : \Carbon\CarbonImmutable::today()->addHours($hour)->translatedFormat('ga') }}
                            </div>
                            @foreach ($periodDays as $day)
                                <div class="relative h-12 border-b border-e border-secondary-soft last:border-e-0 dark:border-line">
                                    @if ($day->isSameDay($today) && $hour === $currentHour)
                                        <div class="pointer-events-none absolute inset-x-0 h-px bg-primary dark:bg-ink" style="top: {{ $currentMinuteFraction * 100 }}%;"></div>
                                    @endif
                                </div>
                            @endforeach
                        @endfor
                    </div>
                </div>
                </div>
            @endif

            <div
                x-show="menuOpen"
                style="display: none"
                x-cloak
                :style="{ top: menuY + 'px', left: menuX + 'px' }"
                class="fixed z-50 min-w-40 rounded-md border border-secondary-soft bg-white py-1 shadow-lg dark:border-line dark:bg-surface-3 dark:shadow-raised"
                data-test="calendar-context-menu"
            >
                @can('create', App\Models\Milestone::class)
                <button
                    type="button"
                    x-on:click="menuOpen = false; $wire.openCreateModal(menuDate)"
                    class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-start text-sm text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-white/[0.06]"
                    data-test="calendar-context-add-milestone"
                >
                    <flux:icon.flag variant="micro" class="size-4" />
                    {{ __('milestones.add_milestone') }}
                </button>
                @endcan
                @can('create', App\Models\Task::class)
                    <button
                        type="button"
                        x-on:click="menuOpen = false; $wire.openCreateTaskModal(menuDate)"
                        class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-start text-sm text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-white/[0.06]"
                        data-test="calendar-context-add-task"
                    >
                        <flux:icon.clipboard-document-check variant="micro" class="size-4" />
                        {{ __('tasks.add_task') }}
                    </button>
                @endcan
            </div>
        </div>

        @if ($focusedType !== null)
            <x-ui.panel :title="__('calendar.related_dates')" :count="$this->focusedItems->count()">
                <div class="flex max-h-96 flex-col gap-2 overflow-y-auto p-2" data-test="calendar-focused-list">
                    @forelse ($this->focusedItems as $item)
                        <div class="flex items-start gap-2 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-line dark:bg-surface-1" wire:key="calendar-focused-item-{{ $item['kind'] }}-{{ $item['id'] }}-{{ $item['date']->toDateString() }}">
                            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary-soft dark:bg-surface-2">
                                @switch($focusedType)
                                    @case('milestone')
                                        <flux:icon.flag variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('task')
                                        <flux:icon.clipboard-document-check variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('report')
                                        <flux:icon.document-text variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('proposal')
                                        <flux:icon.paper-airplane variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('transfer')
                                        <flux:icon.banknotes variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('donor')
                                        <flux:icon.phone variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                    @case('project')
                                        <flux:icon.rocket-launch variant="micro" class="size-3.5 {{ $typeTextClasses[$focusedType] }}" />
                                        @break
                                @endswitch
                            </span>
                            <div class="min-w-0 flex-1">
                                @if ($item['kind'] === 'modal-milestone')
                                    <button type="button" wire:click="openEditModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </button>
                                @elseif ($item['kind'] === 'modal-task')
                                    <button type="button" wire:click="openEditTaskModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </button>
                                @elseif ($item['url'] === null)
                                    <span class="block min-w-0 truncate text-xs font-semibold" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </span>
                                @else
                                    <a href="{{ $item['url'] }}" wire:navigate class="block min-w-0 truncate text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-focused-item-{{ $item['id'] }}">
                                        {{ $item['title'] }}
                                    </a>
                                @endif
                                <p class="mt-0.5 truncate text-[11px] text-secondary-medium dark:text-ink-muted">
                                    {{ $item['date']->translatedFormat('D, j M') }}
                                    @if ($item['subLabel'] !== null)
                                        · {{ $item['subLabel'] }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('calendar.no_related_dates') }}</p>
                    @endforelse
                </div>
            </x-ui.panel>
        @endif

        <x-ui.panel :title="__('tasks.unscheduled')" :count="$calendar['unscheduledTasks']->count()" :subtitle="__('tasks.unscheduled_tasks_have_no_due_date_drag_one_onto_a_day_to_sch')">
            <div class="flex min-h-24 flex-col gap-2 p-2" x-data="taskDropList('my-calendar', (wire, id) => wire.rescheduleTask(id, ''))" data-test="calendar-unscheduled">
                @forelse ($calendar['unscheduledTasks'] as $task)
                    <div
                        class="flex cursor-grab flex-col gap-1.5 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md active:cursor-grabbing dark:border-line dark:bg-surface-1"
                        data-task-id="{{ $task->id }}"
                        wire:key="calendar-unscheduled-{{ $task->id }}"
                    >
                        <button type="button" wire:click.stop="openEditTaskModal({{ $task->id }})" class="truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-unscheduled-{{ $task->id }}">
                            {{ $task->title }}
                        </button>
                        <x-ui.enum-badge :case="$task->priority" class="w-fit" />
                    </div>
                @empty
                    <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('tasks.drop_a_task_here_to_unschedule_it') }}</p>
                @endforelse
            </div>
        </x-ui.panel>
        </div>
    @endif

    <x-ui.modal
        wire:model="showMilestoneModal"
        size="xl"
        :title="$editingMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone')"
        submit="saveMilestone"
        close="closeMilestoneModal"
        required-note
    >
        <x-ui.form-section :title="__('common.section_details')" :fields="['name']">
            <x-ui.form-input wire:model="name" :label="__('milestones.milestone_name')" maxlength="255" required autofocus />
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_placement')" :fields="['milestoneProjectId', 'parentMilestoneId', 'responsibleUserId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                <x-ui.form-select
                    wire:model.live="milestoneProjectId"
                    id="milestone-form-project"
                    :label="__('common.project')"
                    :placeholder="__('common.select_a_project')"
                    :search-label="__('milestones.search_projects')"
                    :disabled="$editingMilestoneId !== null"
                    :locked-reason="__('milestones.a_milestone_cannot_be_moved_to_another_project')"
                    required
                >
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="parentMilestoneId" :label="__('milestones.nest_under')" :search-label="__('milestones.search_milestones')">
                    <flux:select.option value="">{{ __('common.top_level') }}</flux:select.option>
                    @foreach ($this->parentOptions as $parentOption)
                        <flux:select.option :value="$parentOption->id">{{ $parentOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="responsibleUserId" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')">
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedStartDate', 'plannedEndDate', 'milestoneStatus', 'sequence', 'weightPercentage']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="plannedStartDate" :label="__('milestones.planned_start_date')" type="date" required data-test="calendar-form-planned-date" />
                <x-ui.form-input wire:model="plannedEndDate" :label="__('milestones.planned_end_date')" type="date" />

                <x-ui.form-select wire:model="milestoneStatus" :label="__('common.status')" required class="col-span-2 @4xl/modal:col-span-1">
                    @foreach (MilestoneStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-input wire:model="sequence" :label="__('milestones.sequence')" type="number" min="0" required />
                <x-ui.form-input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" suffix="%" required />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="calendar-save-milestone-button">{{ $editingMilestoneId === null ? __('milestones.create_milestone') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        :title="__('milestones.delete_this_milestone')"
        :message="__('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can')"
        confirm="deleteMilestone"
        :confirm-label="__('milestones.delete_milestone')"
        confirm-test="calendar-confirm-delete-milestone"
    />

    <x-ui.modal
        wire:model="showTaskModal"
        size="xl"
        :title="$editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task')"
        :description="__('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option')"
        submit="saveTask"
        close="closeTaskModal"
        required-note
    >
        <x-ui.form-section :title="__('common.section_details')" :fields="['title', 'description']">
            <div class="grid gap-y-3">
                <x-ui.form-input wire:model="title" :label="__('tasks.task_title')" maxlength="255" required autofocus />
                <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_placement')" :fields="['taskSourceType', 'taskSourceId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-select wire:model.live="taskSourceType" :label="__('tasks.source_type')" :placeholder="__('common.select')" required>
                    @foreach ($this->sourceTypeOptions as $sourceTypeKey => $sourceTypeLabel)
                        <flux:select.option :value="$sourceTypeKey">{{ $sourceTypeLabel }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="taskSourceId" :label="__('tasks.source')" :placeholder="__('common.select')" required>
                    @foreach ($this->sourceOptions as $sourceOption)
                        <flux:select.option :value="$sourceOption->id">{{ $sourceOption->name }}{{ $taskSourceType === 'milestone' ? ' · '.$sourceOption->project->name : '' }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('tasks.section_progress')" :fields="['taskStatus', 'taskPriority', 'progressPercentage', 'assignedTo']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <x-ui.form-segmented
                    wire:model="taskStatus"
                    :label="__('common.status')"
                    required
                    class="col-span-2"
                    :options="[
                        ['value' => TaskStatus::Todo->value, 'label' => TaskStatus::Todo->label(), 'dot' => 'border border-secondary-light'],
                        ['value' => TaskStatus::InProgress->value, 'label' => TaskStatus::InProgress->label(), 'dot' => 'bg-status-active'],
                        ['value' => TaskStatus::UnderReview->value, 'label' => TaskStatus::UnderReview->label(), 'dot' => 'bg-status-warn'],
                        ['value' => TaskStatus::Done->value, 'label' => TaskStatus::Done->label(), 'dot' => 'bg-status-good'],
                    ]"
                />

                <x-ui.form-segmented
                    wire:model="taskPriority"
                    :label="__('tasks.priority')"
                    required
                    :options="[
                        ['value' => TaskPriority::Low->value, 'label' => TaskPriority::Low->label(), 'icon' => 'flag', 'iconClass' => 'text-secondary-light'],
                        ['value' => TaskPriority::Medium->value, 'label' => TaskPriority::Medium->label(), 'icon' => 'flag', 'iconClass' => 'text-status-warn'],
                        ['value' => TaskPriority::High->value, 'label' => TaskPriority::High->label(), 'icon' => 'flag', 'iconClass' => 'text-status-critical'],
                    ]"
                />

                <x-ui.form-input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" suffix="%" required />

                <x-ui.form-multiselect wire:model="assignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" class="col-span-2" data-test="task-assignee-picker">
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.form-multiselect>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['startDate', 'dueDate', 'completedDate']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.form-input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" data-test="calendar-task-form-due-date" />
                <x-ui.form-input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="calendar-save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showTaskDeleteModal"
        :title="__('tasks.delete_this_task')"
        confirm="deleteTask"
        :confirm-label="__('tasks.delete_task')"
        confirm-test="calendar-confirm-delete-task"
    />

    <x-ui.modal
        wire:model="showDayDetailModal"
        size="sm"
        :title="$dayDetailDate !== '' ? \Carbon\CarbonImmutable::parse($dayDetailDate)->translatedFormat('l, j F Y') : ''"
        close="closeDayDetail"
        :cancel-label="__('common.close')"
    >
        <div class="flex flex-col gap-2" data-test="calendar-daydetail-list">
            @forelse ($this->dayDetailItems as $item)
                <div class="flex items-start gap-2 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-line dark:bg-surface-1" wire:key="calendar-daydetail-{{ $item['type'] }}-{{ $item['kind'] }}-{{ $item['id'] }}">
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary-soft dark:bg-surface-2">
                        <span class="size-2 rounded-full {{ $typeDotClasses[$item['type']] }}"></span>
                    </span>
                    <div class="min-w-0 flex-1">
                        @if ($item['kind'] === 'modal-milestone')
                            <button type="button" wire:click="closeDayDetail(); openEditModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </button>
                        @elseif ($item['kind'] === 'modal-task')
                            <button type="button" wire:click="closeDayDetail(); openEditTaskModal({{ $item['id'] }})" class="block w-full min-w-0 truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </button>
                        @elseif ($item['url'] === null)
                            <span class="block min-w-0 truncate text-xs font-semibold" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </span>
                        @else
                            <a href="{{ $item['url'] }}" wire:navigate class="block min-w-0 truncate text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-daydetail-item-{{ $item['id'] }}">
                                {{ $item['title'] }}
                            </a>
                        @endif
                        @if ($item['subLabel'] !== null)
                            <p class="mt-0.5 truncate text-[11px] text-secondary-medium dark:text-ink-muted">{{ $item['subLabel'] }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('calendar.no_related_dates') }}</p>
            @endforelse
        </div>
    </x-ui.modal>
</section>
