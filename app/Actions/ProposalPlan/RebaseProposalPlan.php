<?php

declare(strict_types=1);

namespace App\Actions\ProposalPlan;

use App\Enums\ProposalStatus;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\ProposalPlanShift;
use App\Models\ProposalStatusNote;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves a proposal's plan to the grant period the donor approved
 * (contracts.md §12.2, T31), once per approval.
 *
 * System-only: RebaseProposalPlanOnApproval calls it from the queue after the
 * approval has committed, so there is no user to authorize and no
 * Gate::authorize() here. Do not call it from a page.
 *
 * One transaction: the proposal row is locked and reloaded, and the
 * `proposal_plan_shifts` ledger is checked for the approval note. The planned
 * dates of the proposal's milestones and the start/due dates of its plan
 * tasks move by the delta (days from the plan's current start to the grant
 * start); a moved date past the grant end is cut to it. Rows a user re-dated
 * (`planned_dates_edited_at`) are left alone, and dates are written through
 * the query builder, which fires no model events and so never stamps that
 * column. The ledger row and one system `proposal.plan_rebased` activity are
 * written with the shift; the proposal's status and approval data are never
 * touched.
 *
 * Skipped (nothing written, `skipped: true`) when the ledger already holds
 * the note, when a concurrent run claimed it first (the unique index), or
 * when the proposal has no grant dates yet, since there is no period to move
 * to. Usually the delta is `actual_date_begin − planned_date_begin`; after an
 * earlier approval of the same proposal (a re-approval following a status
 * correction) the shifts already applied are subtracted, so the plan never
 * moves twice.
 */
final class RebaseProposalPlan
{
    public const ActivityEvent = 'proposal.plan_rebased';

    /**
     * `clipped` lists the cut milestone ids, then the cut task ids; the
     * activity keeps the two apart.
     *
     * @return array{shifted: int, clipped: list<int>, skipped: bool}
     */
    public function handle(Proposal $proposal, ProposalStatusNote $approvalNote): array
    {
        if ($approvalNote->proposal_id !== $proposal->id || $approvalNote->status !== ProposalStatus::Approved) {
            throw new InvalidArgumentException("Status note [{$approvalNote->id}] is not an approval of proposal [{$proposal->id}].");
        }

        try {
            return DB::transaction(fn (): array => $this->rebase($proposal->id, $approvalNote->id));
        } catch (UniqueConstraintViolationException $exception) {
            // Only the ledger insert can collide: a concurrent run claimed
            // this approval note first, and this run's writes rolled back.
            if (! str_contains($exception->getMessage(), (new ProposalPlanShift)->getTable())) {
                throw $exception;
            }

            return self::skipped();
        }
    }

    /**
     * @return array{shifted: int, clipped: list<int>, skipped: bool}
     */
    private function rebase(int $proposalId, int $approvalNoteId): array
    {
        $proposal = Proposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();

        if (ProposalPlanShift::query()->where('approval_note_id', $approvalNoteId)->exists()) {
            return self::skipped();
        }

        $grantBegin = $proposal->actual_date_begin;
        $grantEnd = $proposal->actual_date_end;

        if ($grantBegin === null || $grantEnd === null) {
            return self::skipped();
        }

        $alreadyShifted = (int) ProposalPlanShift::query()->where('proposal_id', $proposal->id)->sum('delta_days');
        $deltaDays = (int) $proposal->planned_date_begin->diffInDays($grantBegin) - $alreadyShifted;

        $milestones = $this->milestoneMoves($proposal, $deltaDays, $grantEnd);
        $tasks = $this->taskMoves($proposal, $deltaDays, $grantEnd);
        $shifted = count($milestones) + count($tasks);

        ProposalPlanShift::query()->create([
            'proposal_id' => $proposal->id,
            'approval_note_id' => $approvalNoteId,
            'delta_days' => $deltaDays,
            'shifted' => $shifted,
        ]);

        foreach ($milestones as $id => $move) {
            Milestone::query()->whereKey($id)->update(['planned_start_date' => $move['start'], 'planned_end_date' => $move['end']]);
        }

        foreach ($tasks as $id => $move) {
            Task::query()->withoutGlobalScope(TaskVisibilityScope::class)->whereKey($id)->update(['start_date' => $move['start'], 'due_date' => $move['end']]);
        }

        $clippedMilestones = $this->clipped($milestones);
        $clippedTasks = $this->clipped($tasks);

        $this->recordSystemActivity($proposal, [
            'delta_days' => $deltaDays,
            'shifted' => $shifted,
            'clipped_milestones' => $clippedMilestones,
            'clipped_tasks' => $clippedTasks,
            // The sentence's count (ActivityParameters passes `value` through).
            'value' => (string) (count($clippedMilestones) + count($clippedTasks)),
            'project_id' => $this->firstProjectId($proposal),
        ]);

        return [
            'shifted' => $shifted,
            'clipped' => [...array_column($clippedMilestones, 'id'), ...array_column($clippedTasks, 'id')],
            'skipped' => false,
        ];
    }

    /**
     * The proposal's milestones whose planned dates the rebase changes.
     *
     * @return array<int, array{start: string|null, end: string|null, clipped: bool, label: string}> milestone id => move
     */
    private function milestoneMoves(Proposal $proposal, int $deltaDays, CarbonImmutable $grantEnd): array
    {
        $moves = [];

        $milestones = Milestone::query()
            ->where('proposal_id', $proposal->id)
            ->whereNull('planned_dates_edited_at')
            ->orderBy('id')
            ->get(['id', 'name', 'planned_start_date', 'planned_end_date']);

        foreach ($milestones as $milestone) {
            $move = $this->move($milestone->planned_start_date, $milestone->planned_end_date, $deltaDays, $grantEnd);

            if ($move !== null) {
                $moves[$milestone->id] = [...$move, 'label' => $milestone->name];
            }
        }

        return $moves;
    }

    /**
     * The plan tasks (raised on the proposal or on one of its milestones)
     * whose dates the rebase changes. Read past the task visibility scope: a
     * sync queue runs this while the approver is still signed in.
     *
     * @return array<int, array{start: string|null, end: string|null, clipped: bool, label: string}> task id => move
     */
    private function taskMoves(Proposal $proposal, int $deltaDays, CarbonImmutable $grantEnd): array
    {
        $moves = [];

        $tasks = $proposal->planTasks()
            ->withoutGlobalScope(TaskVisibilityScope::class)
            ->whereNull('planned_dates_edited_at')
            ->orderBy('id')
            ->get(['id', 'title', 'start_date', 'due_date']);

        foreach ($tasks as $task) {
            $move = $this->move($task->start_date, $task->due_date, $deltaDays, $grantEnd);

            if ($move !== null) {
                $moves[$task->id] = [...$move, 'label' => $task->title];
            }
        }

        return $moves;
    }

    /**
     * Both dates of one row moved by the delta and cut at the grant end; null
     * when neither changes.
     *
     * @return array{start: string|null, end: string|null, clipped: bool}|null
     */
    private function move(?CarbonImmutable $start, ?CarbonImmutable $end, int $deltaDays, CarbonImmutable $grantEnd): ?array
    {
        $movedStart = $start?->addDays($deltaDays);
        $movedEnd = $end?->addDays($deltaDays);
        $clipped = ($movedStart?->greaterThan($grantEnd) ?? false) || ($movedEnd?->greaterThan($grantEnd) ?? false);

        $move = [
            'start' => $movedStart?->min($grantEnd)->toDateString(),
            'end' => $movedEnd?->min($grantEnd)->toDateString(),
            'clipped' => $clipped,
        ];

        return $move['start'] === $start?->toDateString() && $move['end'] === $end?->toDateString() ? null : $move;
    }

    /**
     * The cut rows by id and name (a task's title), for the activity log and
     * the owners' notification. No amounts: names and titles carry none.
     *
     * @param  array<int, array{start: string|null, end: string|null, clipped: bool, label: string}>  $moves
     * @return list<array{id: int, name: string}>
     */
    private function clipped(array $moves): array
    {
        $clipped = [];

        foreach ($moves as $id => $move) {
            if ($move['clipped']) {
                $clipped[] = ['id' => $id, 'name' => $move['label']];
            }
        }

        return $clipped;
    }

    /**
     * The first linked project, so oversight and links can place the activity.
     */
    private function firstProjectId(Proposal $proposal): ?int
    {
        $projectId = $proposal->projects()->orderBy('projects.id')->value('projects.id');

        return $projectId === null ? null : (int) $projectId;
    }

    /**
     * One activity on the proposal with no actor. ActivityRecorder stamps
     * Auth::id(), which under a sync queue is whoever approved; the rebase is
     * the system's doing, so the row is written here. Its fan-out is queued
     * after the commit, like every other activity's.
     *
     * @param  array<string, mixed>  $properties
     */
    private function recordSystemActivity(Proposal $proposal, array $properties): void
    {
        if (! Activity::isRecording()) {
            return;
        }

        $activity = Activity::query()->create([
            'actor_id' => null,
            'subject_type' => $proposal->getMorphClass(),
            'subject_id' => $proposal->id,
            'program_id' => $proposal->activityProgramId(),
            'module' => $proposal->activityModule(),
            'event' => self::ActivityEvent,
            'changes' => [],
            'properties' => ['label' => $proposal->activityLabel(), 'notify' => true, ...$properties],
        ]);

        FanOutActivity::dispatch($activity->id)->afterCommit();
    }

    /**
     * @return array{shifted: int, clipped: list<int>, skipped: bool}
     */
    private static function skipped(): array
    {
        return ['shifted' => 0, 'clipped' => [], 'skipped' => true];
    }
}
