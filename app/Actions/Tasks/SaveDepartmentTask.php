<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\Currency;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\TaskKind;
use App\Models\HrDepartment;
use App\Models\Proposal;
use App\Models\Task;
use App\Support\EmployeeProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Add or edit a department task on a proposal's plan, and name the person
 * who carries it out (T31, contracts.md §12.2, D-33).
 *
 * One transaction: the proposal row is locked, an existing task is only ever
 * loaded through the proposal's plan (a foreign or unseen id is a 404 with no
 * write), and the plan window is checked against the locked row
 * (TaskPolicy::planDepartmentTask). The task is handed to an HR department;
 * a person is optional and must work in that department. A media deliverable
 * names what it hands over; a financial task's title carries no amount.
 */
final class SaveDepartmentTask
{
    /**
     * Symbols that mark an amount in a title, besides the currency codes.
     */
    private const CurrencySymbols = ['$', '€', 'ل.س'];

    /**
     * @param  array{title?: string, description?: ?string, department_id?: int|string|null, kind?: string, media_kinds?: list<string>|null, requested_on?: ?string, start_date?: ?string, due_date?: ?string, milestone_id?: int|string|null, assignee_employee_id?: int|string|null}  $input
     */
    public function handle(Proposal $proposal, ?int $taskId, array $input): Task
    {
        return DB::transaction(function () use ($proposal, $taskId, $input): Task {
            $locked = $this->lockProposal($proposal);
            $existing = $taskId === null ? null : $locked->planTasks()->whereKey($taskId)->firstOrFail();

            Gate::authorize('planDepartmentTask', [Task::class, $locked, $existing]);

            $milestoneId = $this->nullableId($input['milestone_id'] ?? null);
            $milestone = $milestoneId === null ? null : $locked->milestones()->whereKey($milestoneId)->firstOrFail();

            $validated = $this->validate($existing, [
                ...$input,
                'requested_on' => $this->nullableText($input['requested_on'] ?? null)
                    ?? $existing?->requested_on?->toDateString()
                    ?? CarbonImmutable::today()->toDateString(),
            ]);

            $kind = TaskKind::from($validated['kind']);
            $isDeliverable = $kind === TaskKind::MediaDeliverable;

            $task = $existing ?? new Task;
            $task->fill([
                'source_type' => $milestone === null ? 'proposal' : 'milestone',
                'source_id' => $milestone === null ? $locked->id : $milestone->id,
                'department_id' => (int) $validated['department_id'],
                'kind' => $kind,
                'media_kinds' => $isDeliverable ? array_values(array_unique($validated['media_kinds'] ?? [])) : null,
                'delivery_state' => $isDeliverable ? ($existing->delivery_state ?? DeliveryState::Requested) : null,
                'title' => $validated['title'],
                'description' => $this->nullableText($validated['description'] ?? null),
                'requested_on' => $validated['requested_on'],
                'start_date' => $this->nullableText($validated['start_date'] ?? null),
                'due_date' => $validated['due_date'],
            ]);

            if ($existing === null) {
                $task->created_by = (int) Auth::id();
            }

            $task->save();

            $this->replaceAssignee($task, $this->nullableId($validated['assignee_employee_id'] ?? null));

            return $task;
        });
    }

    /**
     * Name the person who carries out a department task, or clear it (D-33):
     * a tasks.update holder or a member of the task's department, and only
     * someone who works in that department.
     */
    public function assign(Proposal $proposal, int $taskId, ?int $employeeId): Task
    {
        return DB::transaction(function () use ($proposal, $taskId, $employeeId): Task {
            $locked = $this->lockProposal($proposal);
            $task = $locked->planTasks()->whereKey($taskId)->lockForUpdate()->firstOrFail();

            Gate::authorize('assignDepartmentTask', [$task, $locked]);

            if ($employeeId !== null && ! $this->worksInDepartment($employeeId, $task->department_id)) {
                throw ValidationException::withMessages(['assignee_employee_id' => __('department_tasks.assignee_not_in_department')]);
            }

            $this->replaceAssignee($task, $employeeId);

            return $task;
        });
    }

    private function lockProposal(Proposal $proposal): Proposal
    {
        return Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, description?: ?string, department_id: int|string, kind: string, media_kinds?: list<string>|null, requested_on: string, start_date?: ?string, due_date: string, assignee_employee_id?: int|string|null}
     */
    private function validate(?Task $existing, array $input): array
    {
        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['required', 'integer', Rule::exists(HrDepartment::class, 'id')],
            'kind' => ['required', Rule::enum(TaskKind::class)],
            'media_kinds' => ['nullable', 'array', 'required_if:kind,'.TaskKind::MediaDeliverable->value],
            'media_kinds.*' => ['string', Rule::in(DeliverableKind::values())],
            'requested_on' => ['required', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:due_date'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:requested_on'],
            'assignee_employee_id' => ['nullable', 'integer'],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'department_id.required' => __('department_tasks.choose_the_department'),
            'department_id.integer' => __('department_tasks.choose_the_department'),
            'department_id.exists' => __('department_tasks.department_not_found'),
            'media_kinds.required_if' => __('department_tasks.choose_media_kinds'),
            'media_kinds.array' => __('department_tasks.choose_media_kinds'),
            'media_kinds.*.string' => __('department_tasks.choose_media_kinds'),
            'media_kinds.*.in' => __('department_tasks.choose_media_kinds'),
            'start_date.before_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'due_date.required' => __('tasks.set_the_due_date'),
            'due_date.date_format' => __('tasks.set_the_due_date'),
            'due_date.after_or_equal' => __('tasks.due_before_requested'),
            'assignee_employee_id.integer' => __('department_tasks.assignee_not_in_department'),
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($existing, $input): void {
            $errors = $validator->errors();
            $kind = TaskKind::tryFrom((string) ($input['kind'] ?? ''));

            if ($kind === TaskKind::Financial && ! $errors->has('title') && $this->carriesAmount((string) $input['title'])) {
                $errors->add('title', __('department_tasks.financial_title_has_amount'));
            }

            if ($kind !== null && $kind !== TaskKind::MediaDeliverable && $existing !== null && $this->isDelivered($existing)) {
                $errors->add('kind', __('tasks.delivered_deliverable_keeps_its_kind'));
            }

            $assigneeId = $this->nullableId($input['assignee_employee_id'] ?? null);

            if ($assigneeId !== null && ! $errors->has('department_id') && ! $errors->has('assignee_employee_id')
                && ! $this->worksInDepartment($assigneeId, (int) $input['department_id'])) {
                $errors->add('assignee_employee_id', __('department_tasks.assignee_not_in_department'));
            }
        });

        /** @var array{title: string, description?: ?string, department_id: int|string, kind: string, media_kinds?: list<string>|null, requested_on: string, start_date?: ?string, due_date: string, assignee_employee_id?: int|string|null} */
        return $validator->validate();
    }

    /**
     * A 3+ digit number, a currency code or a currency symbol.
     */
    private function carriesAmount(string $title): bool
    {
        if (preg_match('/\p{Nd}{3,}/u', $title) === 1) {
            return true;
        }

        $codes = implode('|', array_map(fn (string $code): string => preg_quote($code, '/'), Currency::values()));

        if (preg_match('/(?<![\p{L}\p{N}])('.$codes.')(?![\p{L}\p{N}])/iu', $title) === 1) {
            return true;
        }

        foreach (self::CurrencySymbols as $symbol) {
            if (str_contains($title, $symbol)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A media deliverable that has been handed over (delivered, accepted or
     * returned) keeps its kind, so its delivery trail stays meaningful.
     */
    private function isDelivered(Task $task): bool
    {
        return $task->kind === TaskKind::MediaDeliverable
            && $task->delivery_state !== null
            && $task->delivery_state !== DeliveryState::Requested;
    }

    private function worksInDepartment(int $employeeId, ?int $departmentId): bool
    {
        return $departmentId !== null && in_array($employeeId, EmployeeProfile::employeeIdsInDepartment($departmentId), true);
    }

    /**
     * A department task names one person or none: replace whatever rows the
     * task carries.
     */
    private function replaceAssignee(Task $task, ?int $employeeId): void
    {
        $staleAssignees = $task->taskAssignees();

        if ($employeeId !== null) {
            $staleAssignees->where('employee_id', '!=', $employeeId);
        }

        $staleAssignees->delete();

        if ($employeeId !== null && ! $task->taskAssignees()->where('employee_id', $employeeId)->exists()) {
            $task->taskAssignees()->create(['employee_id' => $employeeId]);
        }
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
