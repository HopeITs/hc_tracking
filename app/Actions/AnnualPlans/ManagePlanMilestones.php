<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Actions\Tasks\CreateTask;
use App\Enums\ActivityPlanStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanMilestone;
use App\Models\HrEmployee;
use App\Models\PlanIndicator;
use App\Models\Task;
use App\Models\User;
use App\Policies\ActivityPlanPolicy;
use App\Policies\PlanUpdatePolicy;
use App\Support\ActivityPlanScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManagePlanMilestones
{
    public function mayView(User $user, ActivityPlan $plan): bool
    {
        return $user->can('annual-plans.view')
            && ($this->mayManage($user, $plan)
                || $user->hasAnyRole(['MEAL', 'General Manager', 'Super Admin'])
                || ActivityPlanPolicy::isMealManager($user));
    }

    public function mayManage(User $user, ActivityPlan $plan): bool
    {
        if ($plan->status !== ActivityPlanStatus::Approved) {
            return false;
        }

        if (PlanUpdatePolicy::isProgramManager($user, $plan)) {
            return true;
        }

        foreach (ActivityPlanScope::locations($plan) as $location) {
            if (PlanUpdatePolicy::isProjectManager($user, $location)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $input */
    public function save(User $user, ActivityPlan $plan, ?int $milestoneId, array $input): ActivityPlanMilestone
    {
        return DB::transaction(function () use ($user, $plan, $milestoneId, $input): ActivityPlanMilestone {
            $plan = ActivityPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorize($user, $plan);
            $milestone = $milestoneId === null
                ? new ActivityPlanMilestone
                : $plan->milestones()->lockForUpdate()->findOrFail($milestoneId);

            $data = Validator::make($input, [
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'due_date' => ['required', 'date_format:Y-m-d'],
                'measure' => ['required', Rule::in(['checkpoint', 'number', 'percentage'])],
                'plan_indicator_id' => ['nullable', 'required_unless:measure,checkpoint', 'integer'],
                'threshold' => ['nullable', 'required_unless:measure,checkpoint', 'numeric', 'gt:0', 'max:9999999999.99'],
            ])->validate();

            if ($data['measure'] !== 'checkpoint') {
                $target = PlanIndicator::query()->whereKey($data['plan_indicator_id'])
                    ->where('source_type', 'proposal')->where('source_id', $plan->proposal_id)
                    ->whereHas('indicator.result', fn ($query) => $query->where(fn ($query) => $query
                        ->where('program_id', $plan->program_id)->orWhereNull('program_id')))
                    ->first();

                if ($target === null || ($data['measure'] === 'percentage' && (float) $target->target <= 0)) {
                    throw ValidationException::withMessages(['plan_indicator_id' => __('validation.exists', ['attribute' => __('common.target')])]);
                }
            }

            $milestone->fill([
                'activity_plan_id' => $plan->id,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'due_date' => $data['due_date'],
                'measure' => $data['measure'],
                'plan_indicator_id' => $data['measure'] === 'checkpoint' ? null : $data['plan_indicator_id'],
                'threshold' => $data['measure'] === 'checkpoint' ? null : $data['threshold'],
            ]);
            if (! $milestone->exists) {
                $milestone->created_by = $user->id;
            }
            if ($data['measure'] !== 'checkpoint') {
                $milestone->completed_at = null;
            }
            $milestone->save();

            return $milestone;
        });
    }

    public function setCompleted(User $user, ActivityPlan $plan, int $milestoneId, bool $completed): void
    {
        DB::transaction(function () use ($user, $plan, $milestoneId, $completed): void {
            $plan = ActivityPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorize($user, $plan);
            $milestone = $plan->milestones()->lockForUpdate()->findOrFail($milestoneId);
            if ($milestone->measure !== 'checkpoint') {
                throw ValidationException::withMessages(['milestone' => __('validation.in', ['attribute' => 'milestone'])]);
            }
            $milestone->update(['completed_at' => $completed ? now() : null]);
        });
    }

    /**
     * Raise a task on the plan's proposal that supports one of its milestones.
     *
     * @param  array<string, mixed>  $input
     */
    public function addTask(User $user, ActivityPlan $plan, int $milestoneId, array $input): Task
    {
        $this->authorize($user, $plan);

        if (! $user->can('create', Task::class)) {
            throw new AuthorizationException;
        }

        $milestone = $plan->milestones()->findOrFail($milestoneId);

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'assignees' => ['array', 'max:20'],
            'assignees.*' => ['integer', Rule::in(HrEmployee::pickerLabels()->pluck('value')->map(fn ($id): int => (int) $id)->all())],
        ])->validate();

        return DB::transaction(function () use ($user, $plan, $milestone, $data): Task {
            $task = app(CreateTask::class)->handle('proposal', (int) $plan->proposal_id, [
                'newTaskTitle' => trim($data['title']),
                'newTaskStatus' => TaskStatus::Todo->value,
                'newTaskPriority' => TaskPriority::Medium->value,
                'newTaskDueDate' => (string) ($data['due_date'] ?? ''),
                'newTaskAssignedTo' => $data['assignees'] ?? [],
            ], $user->id);

            $task->forceFill(['activity_plan_milestone_id' => $milestone->id])->save();

            return $task;
        });
    }

    public function delete(User $user, ActivityPlan $plan, int $milestoneId): void
    {
        DB::transaction(function () use ($user, $plan, $milestoneId): void {
            $plan = ActivityPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorize($user, $plan);
            $plan->milestones()->lockForUpdate()->findOrFail($milestoneId)->delete();
        });
    }

    private function authorize(User $user, ActivityPlan $plan): void
    {
        if (! $this->mayManage($user, $plan)) {
            throw new AuthorizationException;
        }
    }
}
