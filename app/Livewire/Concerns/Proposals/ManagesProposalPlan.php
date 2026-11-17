<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Tasks\SaveDepartmentTask;
use App\Enums\DeliverableKind;
use App\Enums\ProposalPlanWindow;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Task;
use App\Support\EmployeeProfile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The proposal's Plan tab (T31): the Media, Finance and other department
 * tasks, with the forms that add and edit them. Every write goes through
 * SaveDepartmentTask, which locks the proposal and checks the plan window;
 * this trait only carries the form state and maps the action's refusals onto
 * the fields.
 *
 * The page provides proposal(), tasks() and forgetReadings().
 */
trait ManagesProposalPlan
{
    public bool $showPlanTaskModal = false;

    #[Locked]
    public ?int $editingPlanTaskId = null;

    /** @var array{title: string, description: string, department_id: string, kind: string, media_kinds: list<string>, requested_on: string, start_date: string, due_date: string, assignee_employee_id: string} */
    public array $planTask = ['title' => '', 'description' => '', 'department_id' => '', 'kind' => 'general', 'media_kinds' => [], 'requested_on' => '', 'start_date' => '', 'due_date' => '', 'assignee_employee_id' => ''];

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
    public function canPlanTasks(): bool
    {
        return Gate::inspect('planDepartmentTask', [Task::class, $this->proposal])->allowed();
    }

    /**
     * Every task on the plan: the proposal's department tasks and the work
     * the old Tasks tab listed, once each, through the task visibility scope.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function planTaskRows(): Collection
    {
        $planTasks = $this->proposal->planTasks()
            ->select(['id', 'slug', 'source_type', 'source_id', 'parent_task_id', 'department_id', 'kind', 'media_kinds', 'delivery_state', 'title', 'status', 'priority', 'start_date', 'due_date', 'progress_percentage'])
            ->whereNull('parent_task_id')
            ->with(['taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'source'])
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
            'other' => $ordered->filter(fn (Task $task): bool => in_array($task->kind, [TaskKind::General, TaskKind::Report], true))->values(),
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

    public function openPlanTaskForm(?int $taskId = null, string $kind = 'general'): void
    {
        $this->resetPlanTaskForm();
        $this->planTask['kind'] = TaskKind::tryFrom($kind)?->value ?? TaskKind::General->value;

        if ($taskId !== null) {
            $task = $this->proposalDepartmentTask($taskId);
            $this->editingPlanTaskId = $task->id;
            $this->planTask = [
                'title' => $task->title,
                'description' => (string) $task->description,
                'department_id' => (string) $task->department_id,
                'kind' => $task->kind->value,
                'media_kinds' => $task->media_kinds?->map(fn (DeliverableKind $kind): string => $kind->value)->values()->all() ?? [],
                'requested_on' => $task->requested_on?->toDateString() ?? '',
                'start_date' => (string) $task->start_date?->toDateString(),
                'due_date' => (string) $task->due_date?->toDateString(),
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
        $task = $this->proposalDepartmentTask($taskId);

        $this->resetValidation();
        $this->assigningPlanTaskId = $task->id;
        $this->assigneeEmployeeId = (string) $task->taskAssignees->first()?->employee_id;
        unset($this->assignOptions);
        $this->showAssignModal = true;
    }

    private function proposalDepartmentTask(int $taskId): Task
    {
        return $this->proposal->planTasks()
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->with('taskAssignees')
            ->findOrFail($taskId);
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
     * Put an action's refusal on the form's fields; keys with no field land
     * on the form itself.
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

    private function resetPlanTaskForm(): void
    {
        $this->resetValidation();
        $this->editingPlanTaskId = null;
        $this->planTask = ['title' => '', 'description' => '', 'department_id' => '', 'kind' => TaskKind::General->value, 'media_kinds' => [], 'requested_on' => '', 'start_date' => '', 'due_date' => '', 'assignee_employee_id' => ''];
        unset($this->planTaskAssigneeOptions);
    }

    private function forgetPlanReadings(): void
    {
        unset($this->planTaskRows, $this->planGroups, $this->planGanttPayload, $this->canPlanTasks, $this->assignOptions);
        $this->forgetReadings();
    }
}
