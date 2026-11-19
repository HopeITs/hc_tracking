<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProposalKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Support\FrameworkChecks;
use App\Support\ProposalFramework;
use App\Support\Slots;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The one "fill the logical framework" task of a project proposal, held by
 * the MEAL Manager slot holder.
 *
 * `ensure()` opens it when the proposal reaches Proposal draft
 * (TransitionProposal), due seven days later (user decision 2026-11-19);
 * never for payroll, which has no framework, and never twice. `confirm()` is
 * "Mark framework as done": it needs at least one result, and a reason when
 * any of the FrameworkChecks is still open; it closes the task, records it on
 * the proposal and tells the program manager. `reopen()` opens it again, for
 * the MEAL Manager, the program manager, a PR Manager or whoever created the
 * proposal.
 */
final class SyncLogframeTask
{
    /** Days from Proposal draft to the task's due date (user decision 2026-11-19). */
    public const DUE_IN_DAYS = 7;

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
                'due_date' => CarbonImmutable::today()->addDays(self::DUE_IN_DAYS)->toDateString(),
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
     * Mark the framework as done. A reason is required while any check is
     * still open; it is kept on the task and in the proposal's history.
     *
     * @throws ValidationException when the proposal has no result yet (key "logframe") or a reason is missing (key "note")
     */
    public function confirm(Proposal $proposal, ?string $note = null): Task
    {
        Gate::authorize('results.create');

        if (! Result::query()->proposalFramework($proposal->id)->exists()) {
            throw ValidationException::withMessages(['logframe' => __('results_framework.logframe_confirm_empty')]);
        }

        $note = trim((string) $note);

        if ($note === '' && FrameworkChecks::open(FrameworkChecks::for($proposal->id)) !== []) {
            throw ValidationException::withMessages(['note' => __('proposals.framework.reason_required')]);
        }

        if (mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => __('proposals.framework.reason_too_long')]);
        }

        $task = $this->ensure($proposal) ?? $this->createForActor($proposal);

        if ($task->status !== TaskStatus::Done) {
            DB::transaction(function () use ($task, $proposal, $note): void {
                $task->status = TaskStatus::Done;
                $task->completed_date = CarbonImmutable::today();
                $task->progress_percentage = 100;
                $task->completion_note = $note === '' ? null : $note;
                $task->completed_by = Auth::id();
                $task->save();

                $proposal->recordActivity('proposal.framework_done', [], array_filter(['note' => $note]));
            });

            $this->notifyProgramManagers($proposal);
        }

        return $task;
    }

    /**
     * Open a done framework task again.
     *
     * @throws AuthorizationException when this person may not reopen it
     */
    public function reopen(Proposal $proposal): Task
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $this->mayReopen($user, $proposal)) {
            throw new AuthorizationException(__('proposals.framework.reopen_forbidden'));
        }

        $task = $this->taskOf($proposal);

        if ($task === null || $task->status !== TaskStatus::Done) {
            throw ValidationException::withMessages(['logframe' => __('proposals.framework.not_done')]);
        }

        DB::transaction(function () use ($task, $proposal): void {
            $task->status = TaskStatus::Todo;
            $task->completed_date = null;
            $task->progress_percentage = 0;
            $task->completion_note = null;
            $task->completed_by = null;
            $task->save();

            $proposal->recordActivity('proposal.framework_reopened');
        });

        return $task;
    }

    /**
     * The MEAL Manager, a manager of one of the proposal's programs, a Public
     * Relations Manager, or whoever created the proposal (user decision
     * 2026-11-19).
     */
    public function mayReopen(User $user, Proposal $proposal): bool
    {
        $employeeId = $user->employee_id === null ? null : (int) $user->employee_id;

        if ($employeeId !== null && $employeeId === Slots::holderOf('meal_manager')) {
            return true;
        }

        if ($employeeId !== null && in_array($employeeId, $this->programManagerIds($proposal), true)) {
            return true;
        }

        if ($user->hasRole('Public Relations Manager')) {
            return true;
        }

        $creatorId = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->orderBy('id')->value('changed_by');

        return $creatorId !== null && (int) $creatorId === $user->id;
    }

    /**
     * Tell the program manager(s) that the framework is done, so the
     * proposal can move on. Not the person who marked it.
     */
    private function notifyProgramManagers(Proposal $proposal): void
    {
        $managerIds = $this->programManagerIds($proposal);

        if ($managerIds === []) {
            return;
        }

        User::query()
            ->whereIn('employee_id', $managerIds)
            ->whereKeyNot((int) Auth::id())
            ->get()
            ->each(fn (User $manager) => $manager->notify(new AppNotification(new NotificationPayload(
                kind: NotificationKind::Action,
                module: 'proposals',
                priority: NotificationPriority::Info,
                key: 'notifications.alerts.logframe_confirmed',
                params: ['subject' => $proposal->name],
                subjectType: 'proposal',
                subjectId: $proposal->id,
                actorId: Auth::id(),
            ))));
    }

    /**
     * The HR employee ids of the managers of the proposal's programs.
     *
     * @return list<int>
     */
    private function programManagerIds(Proposal $proposal): array
    {
        $programIds = ProposalFramework::programIds($proposal);

        if ($programIds === []) {
            return [];
        }

        return array_values(array_unique(array_map('intval', Program::query()
            ->whereKey($programIds)
            ->whereNotNull('manager_id')
            ->pluck('manager_id')
            ->all())));
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
