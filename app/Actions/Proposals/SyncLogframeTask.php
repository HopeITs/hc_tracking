<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProposalKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Support\Slots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The one "enter the logical framework" task of a project proposal, held by
 * the MEAL Manager slot holder.
 *
 * `ensure()` opens it when a proposal is created (never for payroll, which has
 * no framework, and never twice). `confirm()` is the "framework is complete"
 * button: it needs at least one result on the proposal, closes the task
 * (done, today, 100%) and creates it first when an older proposal has none.
 * The confirmation is the closed task; reopening is not offered.
 */
final class SyncLogframeTask
{
    /**
     * Open the task for the MEAL Manager. Nothing happens when the slot is
     * vacant or the proposal already has one. No Gate: the caller authorised.
     */
    public function ensure(Proposal $proposal): ?Task
    {
        if ($proposal->kind === ProposalKind::Payroll) {
            return null;
        }

        $existing = $this->taskOf($proposal);

        if ($existing !== null) {
            return $existing;
        }

        $holder = Slots::holderOf('meal_manager');
        $actorId = Auth::id();

        if ($holder === null || $actorId === null) {
            return null;
        }

        return DB::transaction(function () use ($proposal, $holder, $actorId): Task {
            $task = Task::query()->create([
                'source_type' => 'proposal',
                'source_id' => $proposal->id,
                'kind' => TaskKind::Logframe,
                'title' => __('results_framework.logframe_task_title', ['proposal' => $proposal->name]),
                'description' => __('results_framework.logframe_task_description'),
                'due_date' => $proposal->planned_date_begin?->toDateString(),
                'created_by' => $actorId,
            ]);

            $task->taskAssignees()->create(['employee_id' => $holder]);

            return $task;
        });
    }

    /**
     * The proposal's task, open or done.
     */
    public function taskOf(Proposal $proposal): ?Task
    {
        return Task::query()
            ->withoutGlobalScopes()
            ->where('kind', TaskKind::Logframe->value)
            ->where('source_type', 'proposal')
            ->where('source_id', $proposal->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @throws ValidationException when the proposal has no result yet (key "logframe")
     */
    public function confirm(Proposal $proposal): Task
    {
        Gate::authorize('results.create');

        if (! Result::query()->proposalFramework($proposal->id)->exists()) {
            throw ValidationException::withMessages(['logframe' => __('results_framework.logframe_confirm_empty')]);
        }

        $task = $this->ensure($proposal) ?? $this->createForActor($proposal);

        if ($task->status !== TaskStatus::Done) {
            $task->status = TaskStatus::Done;
            $task->completed_date = CarbonImmutable::today();
            $task->progress_percentage = 100;
            $task->save();

            $this->notifyCreator($proposal);
        }

        return $task;
    }

    /**
     * Tell the person who opened the proposal that its framework is done, so
     * the next step (targets and plan) can start. Not the person confirming.
     */
    private function notifyCreator(Proposal $proposal): void
    {
        $creatorId = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->orderBy('id')->value('changed_by');

        if ($creatorId === null || (int) $creatorId === (int) Auth::id()) {
            return;
        }

        User::query()->find($creatorId)?->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action,
            module: 'proposals',
            priority: NotificationPriority::Info,
            key: 'notifications.alerts.logframe_confirmed',
            params: ['subject' => $proposal->name],
            subjectType: 'proposal',
            subjectId: $proposal->id,
            actorId: Auth::id(),
        )));
    }

    /**
     * A vacant MEAL Manager slot leaves nobody to hold the task: the person
     * who confirms holds it, so the confirmation is still recorded.
     */
    private function createForActor(Proposal $proposal): Task
    {
        $task = Task::query()->create([
            'source_type' => 'proposal',
            'source_id' => $proposal->id,
            'kind' => TaskKind::Logframe,
            'title' => __('results_framework.logframe_task_title', ['proposal' => $proposal->name]),
            'created_by' => (int) Auth::id(),
        ]);

        return $task;
    }
}
