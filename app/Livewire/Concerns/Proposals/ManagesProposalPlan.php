<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Milestones\SaveProposalMilestone;
use App\Actions\Tasks\SaveDepartmentTask;
use App\Enums\DeliverableKind;
use App\Enums\ProposalPlanWindow;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Task;
use App\Support\EmployeeProfile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The proposal's Plan tab (T31): its milestones and the Media, Finance and
 * other department tasks, with the forms that add and edit them. Every write
 * goes through SaveProposalMilestone / SaveDepartmentTask, which lock the
 * proposal, load children through it and check the plan window; this trait
 * only carries the form state and maps the actions' refusals onto the fields.
 *
 * The page provides proposal(), tasks() and forgetReadings().
 */
trait ManagesProposalPlan
{
    public bool $showPlanMilestoneModal = false;

    #[Locked]
    public ?int $editingPlanMilestoneId = null;

    /** @var array{name: string, description: string, planned_start_date: string, planned_end_date: string, responsible_employee_id: string, project_id: string, parent_milestone_id: string} */
    public array $planMilestone = ['name' => '', 'description' => '', 'planned_start_date' => '', 'planned_end_date' => '', 'responsible_employee_id' => '', 'project_id' => '', 'parent_milestone_id' => ''];

    public bool $showFinalReportModal = false;

    /** @var array{planned_start_date: string, planned_end_date: string, responsible_employee_id: string, department_id: string, due_date: string} */
    public array $finalReport = ['planned_start_date' => '', 'planned_end_date' => '', 'responsible_employee_id' => '', 'department_id' => '', 'due_date' => ''];

    public bool $showPlanTaskModal = false;

    #[Locked]
    public ?int $editingPlanTaskId = null;

    /** @var array{title: string, description: string, department_id: string, kind: string, media_kinds: list<string>, requested_on: string, start_date: string, due_date: string, milestone_id: string, assignee_employee_id: string} */
    public array $planTask = ['title' => '', 'description' => '', 'department_id' => '', 'kind' => 'general', 'media_kinds' => [], 'requested_on' => '', 'start_date' => '', 'due_date' => '', 'milestone_id' => '', 'assignee_employee_id' => ''];

    public bool $showAssignModal = false;

    #[Locked]
    public ?int $assigningPlanTaskId = null;

    public string $assigneeEmployeeId = '';

    /**
     * Who may edit the plan at this status (contracts §12.2 editing windows).
     */
    #[Computed]
    public function planWindow(): ProposalPlanWindow
    {
        return ProposalPlanWindow::for($this->proposal->status);
    }

    #[Computed]
    public function canPlanMilestones(): bool
    {
        return Gate::inspect('planForProposal', [Milestone::class, $this->proposal])->allowed();
    }

    #[Computed]
    public function canPlanTasks(): bool
    {
        return Gate::inspect('planDepartmentTask', [Task::class, $this->proposal])->allowed();
    }

    /**
     * The proposal's milestones in tree order, each with its depth.
     *
     * @return list<array{milestone: Milestone, depth: int}>
     */
    #[Computed]
    public function planMilestones(): array
    {
        $milestones = Milestone::query()
            ->select(['id', 'slug', 'proposal_id', 'project_id', 'parent_milestone_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status', 'sequence', 'responsible_user_id', 'is_final_report'])
            ->where('proposal_id', $this->proposalId)
            ->with(['responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'project:id,name'])
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();

        $byParent = $milestones->toBase()->groupBy(fn (Milestone $milestone): int => (int) $milestone->parent_milestone_id);
        $ids = $milestones->modelKeys();
        $rows = [];

        $walk = function (int $parentId, int $depth) use (&$walk, $byParent, &$rows): void {
            foreach ($byParent->get($parentId, collect()) as $milestone) {
                $rows[] = ['milestone' => $milestone, 'depth' => $depth];
                $walk($milestone->id, $depth + 1);
            }
        };

        $walk(0, 0);

        // A parent outside this proposal (never written by the plan actions) still shows, at the top.
        foreach ($milestones as $milestone) {
            if ($milestone->parent_milestone_id !== null && ! in_array($milestone->parent_milestone_id, $ids, true)) {
                $rows[] = ['milestone' => $milestone, 'depth' => 0];
            }
        }

        return $rows;
    }

    /**
     * Every task on the plan: the proposal's department tasks and the work
     * the old Tasks tab listed (tasks of the funded projects' milestones),
     * once each, through the task visibility scope.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function planTaskRows(): Collection
    {
        $planTasks = $this->proposal->planTasks()
            ->select(['id', 'slug', 'source_type', 'source_id', 'parent_task_id', 'department_id', 'kind', 'media_kinds', 'delivery_state', 'title', 'status', 'priority', 'start_date', 'due_date', 'progress_percentage'])
            ->whereNull('parent_task_id')
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id']]),
            ])
            ->get();

        return $planTasks->merge($this->tasks->whereNotIn('id', $planTasks->modelKeys()))->values();
    }

    /**
     * The plan's tasks in three groups — Media, Finance, Other — open work
     * first, soonest due first.
     *
     * @return array<string, Collection<int, Task>>
     */
    #[Computed]
    public function planGroups(): array
    {
        $ordered = $this->planTaskRows->sortBy([
            fn (Task $a, Task $b): int => ($a->status === TaskStatus::Done) <=> ($b->status === TaskStatus::Done),
            fn (Task $a, Task $b): int => ($a->due_date === null) <=> ($b->due_date === null),
            fn (Task $a, Task $b): int => ($a->due_date?->getTimestamp() ?? 0) <=> ($b->due_date?->getTimestamp() ?? 0),
            fn (Task $a, Task $b): int => $a->id <=> $b->id,
        ])->values();

        return [
            'media' => $ordered->filter(fn (Task $task): bool => $task->kind === TaskKind::MediaDeliverable)->values(),
            'finance' => $ordered->filter(fn (Task $task): bool => $task->kind === TaskKind::Financial)->values(),
            'other' => $ordered->filter(fn (Task $task): bool => $task->kind === TaskKind::General)->values(),
        ];
    }

    /**
     * @return SupportCollection<int, HrDepartment>
     */
    #[Computed(persist: true, seconds: 300)]
    public function departmentOptions(): SupportCollection
    {
        return HrDepartment::options()->toBase();
    }

    /**
     * The active people of the chosen department, suggested as the assignee
     * (D-33): read from EmployeeProfile, never typed by name.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function planTaskAssigneeOptions(): array
    {
        return $this->departmentPeople((int) $this->planTask['department_id']);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function assignOptions(): array
    {
        $departmentId = $this->assigningPlanTaskId === null ? 0
            : (int) $this->planTaskRows->firstWhere('id', $this->assigningPlanTaskId)?->department_id;

        return $this->departmentPeople($departmentId);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function departmentPeople(int $departmentId): array
    {
        if ($departmentId === 0) {
            return [];
        }

        $ids = array_map('strval', EmployeeProfile::employeeIdsInDepartment($departmentId));

        return array_values(HrEmployee::pickerLabels()
            ->filter(fn (array $option): bool => in_array($option['value'], $ids, true) && ! $option['inactive'])
            ->map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']])
            ->all());
    }

    /**
     * The linked projects a milestone may be tied to (center-specific).
     *
     * @return SupportCollection<int, string>
     */
    #[Computed]
    public function planProjectOptions(): SupportCollection
    {
        return $this->projects->pluck('name', 'id');
    }

    public function openPlanMilestoneForm(?int $milestoneId = null): void
    {
        $this->resetPlanMilestoneForm();

        if ($milestoneId !== null) {
            $milestone = Milestone::query()->where('proposal_id', $this->proposalId)->whereKey($milestoneId)->firstOrFail();
            $this->editingPlanMilestoneId = $milestone->id;
            $this->planMilestone = [
                'name' => $milestone->name,
                'description' => (string) $milestone->description,
                'planned_start_date' => (string) $milestone->planned_start_date?->toDateString(),
                'planned_end_date' => (string) $milestone->planned_end_date?->toDateString(),
                'responsible_employee_id' => (string) $milestone->responsible_user_id,
                'project_id' => (string) $milestone->project_id,
                'parent_milestone_id' => (string) $milestone->parent_milestone_id,
            ];
        }

        $this->showPlanMilestoneModal = true;
    }

    public function savePlanMilestone(SaveProposalMilestone $action): void
    {
        $this->resetValidation();

        try {
            $action->handle($this->proposal, $this->editingPlanMilestoneId, $this->planMilestone);
        } catch (ValidationException $exception) {
            $this->showPlanErrors($exception, 'planMilestone');

            return;
        }

        $created = $this->editingPlanMilestoneId === null;
        $this->closePlanMilestoneModal();
        $this->forgetPlanReadings();
        Flux::toast(variant: 'success', text: $created ? __('milestones.milestone_created') : __('milestones.milestone_updated'));
    }

    public function closePlanMilestoneModal(): void
    {
        $this->showPlanMilestoneModal = false;
        $this->resetPlanMilestoneForm();
    }

    public function openFinalReportForm(): void
    {
        $this->resetValidation();
        $this->finalReport = ['planned_start_date' => '', 'planned_end_date' => '', 'responsible_employee_id' => '', 'department_id' => '', 'due_date' => ''];
        $this->showFinalReportModal = true;
    }

    public function saveFinalReport(SaveProposalMilestone $action): void
    {
        $this->resetValidation();

        try {
            $action->addFinalReport($this->proposal, $this->finalReport);
        } catch (ValidationException $exception) {
            $this->showPlanErrors($exception, 'finalReport');

            return;
        }

        $this->showFinalReportModal = false;
        $this->forgetPlanReadings();
        Flux::toast(variant: 'success', text: __('milestones.final_report_added'));
    }

    public function closeFinalReportModal(): void
    {
        $this->showFinalReportModal = false;
        $this->resetValidation();
    }

    public function openPlanTaskForm(?int $taskId = null, string $kind = 'general'): void
    {
        $this->resetPlanTaskForm();
        $this->planTask['kind'] = TaskKind::tryFrom($kind)?->value ?? TaskKind::General->value;

        if ($taskId !== null) {
            $task = $this->planTaskRows->firstWhere('id', $taskId) ?? throw new ModelNotFoundException;
            $source = $task->source;
            $this->editingPlanTaskId = $task->id;
            $this->planTask = [
                'title' => $task->title,
                'description' => (string) Task::query()->whereKey($task->id)->value('description'),
                'department_id' => (string) $task->department_id,
                'kind' => $task->kind->value,
                'media_kinds' => $task->media_kinds?->map(fn (DeliverableKind $kind): string => $kind->value)->values()->all() ?? [],
                'requested_on' => (string) Task::query()->whereKey($task->id)->value('requested_on'),
                'start_date' => (string) $task->start_date?->toDateString(),
                'due_date' => (string) $task->due_date?->toDateString(),
                'milestone_id' => $source instanceof Milestone && $source->proposal_id === $this->proposalId ? (string) $source->id : '',
                'assignee_employee_id' => (string) $task->taskAssignees->first()?->employee_id,
            ];
        }

        $this->showPlanTaskModal = true;
    }

    public function savePlanTask(SaveDepartmentTask $action): void
    {
        $this->resetValidation();

        try {
            $action->handle($this->proposal, $this->editingPlanTaskId, $this->planTask);
        } catch (ValidationException $exception) {
            $this->showPlanErrors($exception, 'planTask');

            return;
        }

        $created = $this->editingPlanTaskId === null;
        $this->closePlanTaskModal();
        $this->forgetPlanReadings();
        Flux::toast(variant: 'success', text: $created ? __('department_tasks.task_added') : __('department_tasks.task_updated'));
    }

    public function closePlanTaskModal(): void
    {
        $this->showPlanTaskModal = false;
        $this->resetPlanTaskForm();
    }

    /**
     * A changed department drops a picked person who does not work there.
     */
    public function updatedPlanTask(mixed $value, string $key): void
    {
        if ($key !== 'department_id') {
            return;
        }

        unset($this->planTaskAssigneeOptions);

        if (! in_array($this->planTask['assignee_employee_id'], array_column($this->planTaskAssigneeOptions, 'value'), true)) {
            $this->planTask['assignee_employee_id'] = '';
        }
    }

    public function openAssignModal(int $taskId): void
    {
        $task = $this->planTaskRows->firstWhere('id', $taskId) ?? throw new ModelNotFoundException;

        $this->resetValidation();
        $this->assigningPlanTaskId = $task->id;
        $this->assigneeEmployeeId = (string) $task->taskAssignees->first()?->employee_id;
        unset($this->assignOptions);
        $this->showAssignModal = true;
    }

    public function saveAssignee(SaveDepartmentTask $action): void
    {
        if ($this->assigningPlanTaskId === null) {
            return;
        }

        $this->resetValidation();

        try {
            $action->assign($this->proposal, $this->assigningPlanTaskId, $this->assigneeEmployeeId === '' ? null : (int) $this->assigneeEmployeeId);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->addError('assigneeEmployeeId', $message);
                }
            }

            return;
        }

        $this->closeAssignModal();
        $this->forgetPlanReadings();
        Flux::toast(variant: 'success', text: __('department_tasks.assignee_saved'));
    }

    public function closeAssignModal(): void
    {
        $this->showAssignModal = false;
        $this->assigningPlanTaskId = null;
        $this->assigneeEmployeeId = '';
    }

    /**
     * Put an action's refusal on the form's fields; keys with no field (the
     * final report's "already exists") land on the form itself.
     */
    private function showPlanErrors(ValidationException $exception, string $form): void
    {
        $fields = array_keys($this->{$form});

        foreach ($exception->errors() as $key => $messages) {
            $field = str($key)->before('.')->toString();

            foreach ($messages as $message) {
                $this->addError(in_array($field, $fields, true) ? $form.'.'.$field : $form, $message);
            }
        }
    }

    private function resetPlanMilestoneForm(): void
    {
        $this->resetValidation();
        $this->editingPlanMilestoneId = null;
        $this->planMilestone = ['name' => '', 'description' => '', 'planned_start_date' => '', 'planned_end_date' => '', 'responsible_employee_id' => '', 'project_id' => '', 'parent_milestone_id' => ''];
    }

    private function resetPlanTaskForm(): void
    {
        $this->resetValidation();
        $this->editingPlanTaskId = null;
        $this->planTask = ['title' => '', 'description' => '', 'department_id' => '', 'kind' => TaskKind::General->value, 'media_kinds' => [], 'requested_on' => '', 'start_date' => '', 'due_date' => '', 'milestone_id' => '', 'assignee_employee_id' => ''];
        unset($this->planTaskAssigneeOptions);
    }

    private function forgetPlanReadings(): void
    {
        unset($this->planMilestones, $this->planTaskRows, $this->planGroups, $this->planGanttPayload, $this->canPlanMilestones, $this->canPlanTasks, $this->assignOptions);
        $this->forgetReadings();
    }
}
