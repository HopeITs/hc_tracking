<?php

use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
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

    #[Url(except: '')]
    public string $filterEmployeeId = '';

    public bool $showMilestones = true;
    public bool $showTasks = true;
    public bool $showReports = true;
    public bool $showTypeFilterMenu = false;

    public bool $showMilestoneModal = false;
    public bool $showDeleteModal = false;
    public ?int $editingMilestoneId = null;
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
    public ?int $editingTaskId = null;
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
     * The employee whose items the calendar shows: the one chosen in the
     * responsible-user filter, defaulting to the signed-in user's own
     * employee record.
     */
    protected function scopedEmployeeId(): ?int
    {
        return $this->filterEmployeeId !== '' ? (int) $this->filterEmployeeId : $this->employeeId();
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
     * @return array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: Collection<string, Collection<int, Milestone>>, tasksByDay: Collection<string, Collection<int, Task>>, reportsByDay: Collection<string, Collection<int, Report>>, nowOffsetPercent: float}
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

        $employeeId = $this->scopedEmployeeId();

        $milestonesByDay = $employeeId === null || ! $this->showMilestones
            ? new Collection
            : Milestone::query()
                ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status', 'weight_percentage', 'responsible_user_id'])
                ->where('responsible_user_id', $employeeId)
                ->whereBetween('planned_start_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->with('project:id,name')
                ->withCount(['children', 'tasks'])
                ->orderBy('planned_start_date')
                ->orderBy('sequence')
                ->get()
                ->groupBy(fn (Milestone $milestone): string => $milestone->planned_start_date->toDateString());

        $tasksByDay = $employeeId === null || ! $this->showTasks
            ? new Collection
            : Task::query()
                ->select(['id', 'title', 'status', 'due_date'])
                ->whereHas('taskAssignees', fn (Builder $query) => $query->where('employee_id', $employeeId))
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->orderBy('due_date')
                ->get()
                ->groupBy(fn (Task $task): string => $task->due_date->toDateString());

        $reportUserIds = $employeeId === null
            ? []
            : User::query()->where('employee_id', $employeeId)->pluck('id')->all();

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

        return [
            'month' => $month,
            'weeks' => $weeks,
            'days' => $days,
            'milestonesByDay' => $milestonesByDay,
            'tasksByDay' => $tasksByDay,
            'reportsByDay' => $reportsByDay,
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
            'program' => Program::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get(),
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
        $this->resetMilestoneForm();

        if ($date !== null) {
            $this->plannedStartDate = $date;
        }

        $this->responsibleUserId = (string) ($this->scopedEmployeeId() ?? '');
        $this->showMilestoneModal = true;
    }

    public function openEditModal(int $milestoneId): void
    {
        $milestone = Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->findOrFail($milestoneId);

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
        $this->deletingMilestoneId = $milestoneId;
        $this->showDeleteModal = true;
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        $milestone = Milestone::query()->find($this->deletingMilestoneId);
        $deleted = $milestone !== null && $milestone->children()->doesntExist() && $milestone->tasks()->doesntExist()
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
        $this->resetTaskForm();

        if ($date !== null) {
            $this->dueDate = $date;
        }

        $this->assignedTo = $this->scopedEmployeeId() !== null ? [(string) $this->scopedEmployeeId()] : [];
        $this->showTaskModal = true;
    }

    public function openEditTaskModal(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->findOrFail($taskId);

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
        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
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

            if ($task === null || $task->subtasks()->exists()) {
                return false;
            }

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
};
?>

@php
    $calendar = $this->calendar;
    $today = \Carbon\CarbonImmutable::today();
    $hasEmployee = auth()->user()->employee_id !== null;

    $markerTone = function (Milestone $milestone): string {
        if ($milestone->isMissed() || $milestone->isOverdue()) {
            return 'bg-status-critical-soft text-primary';
        }

        return match ($milestone->status) {
            MilestoneStatus::Reached => 'bg-status-good-soft text-status-good',
            MilestoneStatus::Ongoing => 'bg-status-warn-soft text-status-warn',
            MilestoneStatus::Postponed, MilestoneStatus::Cancelled => 'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft',
            MilestoneStatus::Planned => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium',
        };
    };

    $taskTone = fn (TaskStatus $status): string => match ($status) {
        TaskStatus::Done => 'bg-status-good-soft text-status-good',
        TaskStatus::InProgress, TaskStatus::UnderReview => 'bg-funding-soft text-funding',
        TaskStatus::Todo => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium',
    };

    $reportTone = fn (ReportStatus $status): string => match ($status) {
        ReportStatus::Approved => 'bg-status-good-soft text-status-good',
        ReportStatus::Reviewed => 'bg-funding-soft text-funding',
        ReportStatus::Draft => 'bg-status-warn-soft text-status-warn',
    };

    // A dot in front of each chip carries the item TYPE, independent of the
    // tone above (which carries its STATUS) — so a Reached milestone and a
    // Done task both render green fills but stay visually distinguishable.
    $typeDotClasses = [
        'milestone' => 'bg-funding',
        'task' => 'bg-status-warn',
        'report' => 'bg-primary',
    ];

    $selectedMilestoneProject = $milestoneProjectId === '' ? null : $this->projectOptions->firstWhere('id', (int) $milestoneProjectId);
    $selectedParentMilestone = $parentMilestoneId === '' ? null : $this->parentOptions->firstWhere('id', (int) $parentMilestoneId);
    $selectedResponsibleOption = $responsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $responsibleUserId);
    $selectedTaskSource = $taskSourceId === '' ? null : $this->sourceOptions->firstWhere('id', (int) $taskSourceId);
    $selectedFilterEmployee = $filterEmployeeId === '' ? null : $this->userOptions->firstWhere('id', (int) $filterEmployeeId);
    $dropdownLabelClasses = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft';

    $periodHeading = match ($viewMode) {
        'month' => $calendar['month']->translatedFormat(__('formats.month_year')),
        'day' => $calendar['days'][0]->translatedFormat('l, F j'),
        default => $calendar['days'][0]->translatedFormat('F j').' - '.end($calendar['days'])->translatedFormat('F j'),
    };
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="calendar-heading">
    <x-ui.page-header heading-id="calendar-heading" :title="__('common.my_calendar')" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button size="sm" wire:click="goToToday" data-test="calendar-today-button">{{ __('common.today') }}</x-ui.button>

            <flux:dropdown position="bottom-start" class="filter-picker">
                <x-ui.button size="sm" icon:trailing="chevron-down" data-test="calendar-view-mode-button">{{ __('calendar.'.$viewMode) }}</x-ui.button>
                <flux:menu class="filter-menu">
                    <div class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">{{ __('calendar.time_period') }}</div>
                    @foreach ([['day', 'D'], ['four_days', '4'], ['week', 'W'], ['month', 'M']] as [$periodKey, $shortcut])
                        <flux:menu.item wire:click="setViewMode('{{ $periodKey }}')" data-test="calendar-view-mode-{{ $periodKey }}">
                            <span class="min-w-0 flex-1 truncate">{{ __('calendar.'.$periodKey) }}</span>
                            <kbd class="me-1 rounded border border-secondary-soft px-1.5 py-0.5 text-[10px] font-semibold dark:border-secondary-medium">{{ $shortcut }}</kbd>
                            @if ($viewMode === $periodKey)<flux:icon.check class="size-4 shrink-0 text-primary" />@endif
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

        <div class="flex items-center gap-2">
            <div class="filter-picker relative">
                <button
                    type="button"
                    data-flux-button
                    wire:click="$toggle('showTypeFilterMenu')"
                    aria-expanded="{{ $showTypeFilterMenu ? 'true' : 'false' }}"
                    aria-haspopup="listbox"
                    aria-label="{{ __('calendar.filter_by_type') }}"
                    data-test="calendar-type-filter-button"
                    class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-2.5"
                >
                    <flux:icon.funnel variant="micro" class="size-4" />
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
                        <button type="button" data-flux-menu-item wire:click="$toggle('showMilestones')" role="option" aria-selected="{{ $showMilestones ? 'true' : 'false' }}" class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="calendar-filter-milestones">
                            <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['milestone'] }}"></span>
                            <span class="min-w-0 flex-1 truncate">{{ __('tasks.milestone') }}</span>
                            @if ($showMilestones)<flux:icon.check class="size-4 shrink-0 text-primary" />@endif
                        </button>
                        <button type="button" data-flux-menu-item wire:click="$toggle('showTasks')" role="option" aria-selected="{{ $showTasks ? 'true' : 'false' }}" class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="calendar-filter-tasks">
                            <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['task'] }}"></span>
                            <span class="min-w-0 flex-1 truncate">{{ __('tasks.task') }}</span>
                            @if ($showTasks)<flux:icon.check class="size-4 shrink-0 text-primary" />@endif
                        </button>
                        <button type="button" data-flux-menu-item wire:click="$toggle('showReports')" role="option" aria-selected="{{ $showReports ? 'true' : 'false' }}" class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="calendar-filter-reports">
                            <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['report'] }}"></span>
                            <span class="min-w-0 flex-1 truncate">{{ __('calendar.report_deadline') }}</span>
                            @if ($showReports)<flux:icon.check class="size-4 shrink-0 text-primary" />@endif
                        </button>
                    </div>
                @endif
            </div>

            <flux:tooltip :content="__('calendar.filter_by_responsible')">
            <flux:dropdown position="bottom-end" class="filter-picker">
                <x-ui.button size="sm" :aria-label="__('calendar.filter_by_responsible')" data-test="calendar-responsible-filter-button">
                    <flux:icon.user variant="micro" class="size-4" />
                </x-ui.button>
                <flux:menu class="filter-menu" x-data="{ optionSearch: '' }">
                    @if ($this->userOptions->count() > 6)
                        <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                            <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                        </div>
                    @endif
                    <flux:menu.item wire:click="$set('filterEmployeeId', '')" data-test="calendar-responsible-filter-me">
                        <span class="min-w-0 truncate">{{ __('calendar.me') }}</span>
                        @if ($filterEmployeeId === '')<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                    </flux:menu.item>
                    @foreach ($this->userOptions as $userOption)
                        <flux:menu.item wire:click="$set('filterEmployeeId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'calendar-responsible-filter-option-'.$userOption->id" data-test="calendar-responsible-filter-option-{{ $userOption->id }}">
                            <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                            @if ($filterEmployeeId !== '' && (int) $filterEmployeeId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            </flux:tooltip>

            <flux:dropdown position="bottom-end" class="filter-picker">
                <x-ui.button tone="primary" icon="plus" icon:trailing="chevron-down" data-test="calendar-add-button">{{ __('common.add') }}</x-ui.button>
                <flux:menu class="filter-menu">
                    <flux:menu.item wire:click="openCreateModal" data-test="calendar-add-milestone-button">
                        <flux:icon.flag variant="micro" class="size-4" />
                        {{ __('milestones.add_milestone') }}
                    </flux:menu.item>
                    <flux:menu.item wire:click="openCreateTaskModal" data-test="calendar-add-task-button">
                        <flux:icon.clipboard-document-check variant="micro" class="size-4" />
                        {{ __('tasks.add_task') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if (! $hasEmployee)
        <x-ui.panel>
            <x-ui.empty-state icon="user" :heading="__('calendar.no_linked_employee')" :body="__('calendar.link_your_account_to_see_your_calendar')" />
        </x-ui.panel>
    @else
        <div class="flex flex-wrap items-center gap-4 text-xs text-secondary-medium dark:text-secondary-soft">
            <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['milestone'] }}"></span>{{ __('tasks.milestone') }}</span>
            <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['task'] }}"></span>{{ __('tasks.task') }}</span>
            <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['report'] }}"></span>{{ __('calendar.report_deadline') }}</span>
        </div>

        <div
            class="relative overflow-hidden rounded-xl border border-secondary-soft dark:border-secondary-medium"
            x-data="{ menuOpen: false, menuX: 0, menuY: 0, menuDate: '' }"
            x-on:click.away="menuOpen = false"
            x-on:keydown.escape.window="menuOpen = false"
        >
            @if ($viewMode === 'month')
                <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-xs font-semibold uppercase tracking-wide text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/40 dark:text-secondary-soft">
                    @foreach ($calendar['weeks'][0] as $weekdayHeader)
                        <div class="py-2">{{ $weekdayHeader->translatedFormat('D') }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7">
                    @foreach ($calendar['weeks'] as $week)
                        @foreach ($week as $day)
                            @php
                                $dayMilestones = $calendar['milestonesByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                                $dayTasks = $calendar['tasksByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                                $dayReports = $calendar['reportsByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                                $isCurrentMonth = $day->month === $calendar['month']->month;
                                $isToday = $day->isSameDay($today);
                            @endphp
                            <div
                                wire:click="openCreateModal('{{ $day->toDateString() }}')"
                                wire:key="calendar-day-{{ $day->toDateString() }}"
                                x-on:contextmenu.prevent="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                                class="flex min-h-28 cursor-pointer flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start last:border-e-0 [&:nth-child(7n)]:border-e-0 hover:bg-secondary-soft/40 dark:border-secondary-medium dark:hover:bg-secondary-medium/40 {{ $isCurrentMonth ? '' : 'opacity-40' }}"
                                data-test="calendar-day-{{ $day->toDateString() }}"
                            >
                                <span @class([
                                    'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                    'bg-primary text-white' => $isToday,
                                    'text-secondary-dark dark:text-white' => ! $isToday,
                                ])>
                                    {{ $day->day }}
                                </span>

                                @include('pages.calendar._item-chips', ['dayMilestones' => $dayMilestones, 'dayTasks' => $dayTasks, 'dayReports' => $dayReports, 'markerTone' => $markerTone, 'taskTone' => $taskTone, 'reportTone' => $reportTone, 'typeDotClasses' => $typeDotClasses])
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
                    <div class="border-b border-e border-secondary-soft dark:border-secondary-medium"></div>
                    @foreach ($periodDays as $day)
                        @php $isToday = $day->isSameDay($today); @endphp
                        <div class="border-b border-e border-secondary-soft p-2 text-center last:border-e-0 dark:border-secondary-medium">
                            <div @class(['text-sm font-semibold', 'text-primary' => $isToday, 'text-secondary-dark dark:text-white' => ! $isToday])>{{ $day->translatedFormat('l') }}</div>
                            <div @class(['text-xs', 'text-primary' => $isToday, 'text-secondary-medium dark:text-secondary-soft' => ! $isToday])>{{ $day->translatedFormat('j M') }}</div>
                        </div>
                    @endforeach

                    <div class="flex items-center justify-end border-e border-secondary-soft px-2 py-1.5 text-[11px] font-semibold text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">{{ __('calendar.all_day') }}</div>
                    @foreach ($periodDays as $day)
                        @php
                            $dayMilestones = $calendar['milestonesByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                            $dayTasks = $calendar['tasksByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                            $dayReports = $calendar['reportsByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                        @endphp
                        <div
                            wire:click="openCreateModal('{{ $day->toDateString() }}')"
                            wire:key="calendar-allday-{{ $day->toDateString() }}"
                            x-on:contextmenu.prevent="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                            class="flex min-h-10 cursor-pointer flex-col gap-1 border-e border-secondary-soft p-1.5 text-start last:border-e-0 hover:bg-secondary-soft/40 dark:border-secondary-medium dark:hover:bg-secondary-medium/40"
                            data-test="calendar-allday-{{ $day->toDateString() }}"
                        >
                            @include('pages.calendar._item-chips', ['dayMilestones' => $dayMilestones, 'dayTasks' => $dayTasks, 'dayReports' => $dayReports, 'markerTone' => $markerTone, 'taskTone' => $taskTone, 'reportTone' => $reportTone, 'typeDotClasses' => $typeDotClasses])
                        </div>
                    @endforeach
                </div>

                <div class="max-h-96 overflow-y-auto border-t border-secondary-soft dark:border-secondary-medium" x-init="$el.scrollTop = Math.max(0, {{ $currentHour }} * 48 - 160)">
                    <div class="grid" style="{{ $gridColumns }}">
                        @for ($hour = 0; $hour < 24; $hour++)
                            <div class="h-12 border-b border-e border-secondary-soft px-2 py-1 text-end text-[11px] text-secondary-medium dark:border-secondary-medium dark:text-secondary-soft">
                                {{ $hour === 0 ? '' : \Carbon\CarbonImmutable::today()->addHours($hour)->translatedFormat('ga') }}
                            </div>
                            @foreach ($periodDays as $day)
                                <div class="relative h-12 border-b border-e border-secondary-soft last:border-e-0 dark:border-secondary-medium">
                                    @if ($day->isSameDay($today) && $hour === $currentHour)
                                        <div class="pointer-events-none absolute inset-x-0 h-px bg-primary" style="top: {{ $currentMinuteFraction * 100 }}%;"></div>
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
                class="fixed z-50 min-w-40 rounded-md border border-secondary-soft bg-white py-1 shadow-lg dark:border-secondary-medium dark:bg-secondary-dark"
                data-test="calendar-context-menu"
            >
                <button
                    type="button"
                    x-on:click="menuOpen = false; $wire.openCreateModal(menuDate)"
                    class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-start text-sm text-secondary-dark hover:bg-secondary-soft dark:text-white dark:hover:bg-secondary-medium"
                    data-test="calendar-context-add-milestone"
                >
                    <flux:icon.flag variant="micro" class="size-4" />
                    {{ __('milestones.add_milestone') }}
                </button>
                <button
                    type="button"
                    x-on:click="menuOpen = false; $wire.openCreateTaskModal(menuDate)"
                    class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-start text-sm text-secondary-dark hover:bg-secondary-soft dark:text-white dark:hover:bg-secondary-medium"
                    data-test="calendar-context-add-task"
                >
                    <flux:icon.clipboard-document-check variant="micro" class="size-4" />
                    {{ __('tasks.add_task') }}
                </button>
            </div>
        </div>
    @endif

    <flux:modal wire:model="showMilestoneModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveMilestone" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone') }}</flux:heading>
            </div>

            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="milestone-form-project" class="{{ $dropdownLabelClasses }}">{{ __('common.project') }}</label>
                    @if ($editingMilestoneId !== null)
                        <flux:tooltip :content="__('milestones.a_milestone_cannot_be_moved_to_another_project')">
                            @include('pages.milestones._project-dropdown', ['disabled' => true])
                        </flux:tooltip>
                    @else
                        @include('pages.milestones._project-dropdown', ['disabled' => false])
                    @endif
                    <flux:error name="milestoneProjectId" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label for="milestone-form-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-form-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
                            <span class="truncate">{{ $selectedParentMilestone?->name ?? __('common.top_level') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->parentOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_milestones')" :placeholder="__('milestones.search_milestones')" />
                                </div>
                            @endif
                            <flux:menu.item wire:click="$set('parentMilestoneId', '')">
                                {{ __('common.top_level') }}
                                @if ($parentMilestoneId === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                            </flux:menu.item>
                            @foreach ($this->parentOptions as $parentOption)
                                <flux:menu.item wire:click="$set('parentMilestoneId', {{ $parentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-parent-option-'.$parentOption->id">
                                    <span class="min-w-0 truncate">{{ $parentOption->name }}</span>
                                    @if ((int) $parentMilestoneId === $parentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="parentMilestoneId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedStartDate" :label="__('milestones.planned_start_date')" type="date" required data-test="calendar-form-planned-date" />
                <x-ui.input wire:model="plannedEndDate" :label="__('milestones.planned_end_date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="milestone-form-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="milestone-form-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                            <span class="truncate">{{ MilestoneStatus::from($milestoneStatus)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (MilestoneStatus::cases() as $statusCase)
                                <flux:menu.item wire:click="$set('milestoneStatus', '{{ $statusCase->value }}')" :wire:key="'milestone-form-status-option-'.$statusCase->value">
                                    <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                    @if ($milestoneStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="milestoneStatus" class="mt-1.5" />
                </div>

                <x-ui.input wire:model="sequence" :label="__('milestones.sequence')" type="number" min="0" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />

                <div class="min-w-0">
                    <label for="milestone-form-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-form-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedResponsibleOption ? $selectedResponsibleOption->name.' · '.$selectedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('responsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $responsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="responsibleUserId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeMilestoneModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="calendar-save-milestone-button">{{ $editingMilestoneId === null ? __('milestones.create_milestone') : __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('milestones.delete_this_milestone') }}</flux:heading>
            <flux:subheading>{{ __('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can') }}</flux:subheading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('showDeleteModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteMilestone" data-test="calendar-confirm-delete-milestone">{{ __('milestones.delete_milestone') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showTaskModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveTask" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task') }}</flux:heading>
                <flux:subheading>{{ __('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="title" :label="__('tasks.task_title')" type="text" maxlength="255" required autofocus />
            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="task-form-source-type" class="{{ $dropdownLabelClasses }}">{{ __('tasks.source_type') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="task-form-source-type" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('tasks.source_type')">
                            <span class="truncate">{{ $this->sourceTypeOptions[$taskSourceType] ?? __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach ($this->sourceTypeOptions as $sourceTypeKey => $sourceTypeLabel)
                                <flux:menu.item wire:click="$set('taskSourceType', '{{ $sourceTypeKey }}')" :wire:key="'task-form-source-type-option-'.$sourceTypeKey">
                                    <span class="min-w-0 truncate">{{ $sourceTypeLabel }}</span>
                                    @if ($taskSourceType === $sourceTypeKey)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="taskSourceType" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label for="task-form-source" class="{{ $dropdownLabelClasses }}">{{ __('tasks.source') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="task-form-source" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('tasks.source')">
                            <span class="truncate">{{ $selectedTaskSource?->name ?? __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->sourceOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('tasks.source')" :placeholder="__('tasks.source')" />
                                </div>
                            @endif
                            @foreach ($this->sourceOptions as $sourceOption)
                                <flux:menu.item wire:click="$set('taskSourceId', {{ $sourceOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'task-form-source-option-'.$sourceOption->id">
                                    <span class="min-w-0 truncate">{{ $sourceOption->name }}{{ $taskSourceType === 'milestone' ? ' · '.$sourceOption->project->name : '' }}</span>
                                    @if ((int) $taskSourceId === $sourceOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="taskSourceId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 @2xl/directory:grid-cols-4">
                <div class="min-w-0">
                    <label for="task-form-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="task-form-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                            <span class="truncate">{{ TaskStatus::from($taskStatus)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (TaskStatus::cases() as $statusCase)
                                <flux:menu.item wire:click="$set('taskStatus', '{{ $statusCase->value }}')" :wire:key="'task-form-status-option-'.$statusCase->value">
                                    <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                    @if ($taskStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="taskStatus" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label for="task-form-priority" class="{{ $dropdownLabelClasses }}">{{ __('tasks.priority') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="task-form-priority" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('tasks.priority')">
                            <span class="truncate">{{ TaskPriority::from($taskPriority)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (TaskPriority::cases() as $priorityCase)
                                <flux:menu.item wire:click="$set('taskPriority', '{{ $priorityCase->value }}')" :wire:key="'task-form-priority-option-'.$priorityCase->value">
                                    <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                                    @if ($taskPriority === $priorityCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="taskPriority" class="mt-1.5" />
                </div>

                <x-ui.input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" required />

                <div class="min-w-0">
                    <label class="{{ $dropdownLabelClasses }}">{{ __('tasks.assignees') }}</label>
                    @php
                        $selectedAssigneeOptions = $this->userOptions->whereIn('id', collect($assignedTo)->map(fn ($id) => (int) $id));
                        $assigneeTriggerLabel = match (true) {
                            $selectedAssigneeOptions->isEmpty() => __('common.select'),
                            $selectedAssigneeOptions->count() <= 2 => $selectedAssigneeOptions->map->name->implode(', '),
                            default => __('common.count_selected', ['count' => $selectedAssigneeOptions->count()]),
                        };
                    @endphp
                    <x-ui.multi-select-popover
                        :label="__('tasks.assignees')"
                        in-modal
                        class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-secondary-light bg-white px-3 py-2 text-start text-sm dark:border-secondary-light dark:bg-secondary-medium"
                        data-test="task-assignee-picker"
                    >
                        <x-slot:trigger>
                            <span class="min-w-0 truncate">{{ $assigneeTriggerLabel }}</span>
                            <flux:icon.chevron-up-down class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" aria-hidden="true" />
                        </x-slot:trigger>

                        @foreach ($this->userOptions as $userOption)
                            <label
                                x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                            >
                                <input type="checkbox" wire:model.live="assignedTo" value="{{ $userOption->id }}" class="rounded border-secondary-light text-primary focus:ring-primary" />
                                <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                            </label>
                        @endforeach
                    </x-ui.multi-select-popover>
                    <flux:error name="assignedTo" class="mt-1.5" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" data-test="calendar-task-form-due-date" />
                <x-ui.input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeTaskModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="calendar-save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showTaskDeleteModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('tasks.delete_this_task') }}</flux:heading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('showTaskDeleteModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteTask" data-test="calendar-confirm-delete-task">{{ __('tasks.delete_task') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
