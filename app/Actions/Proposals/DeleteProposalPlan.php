<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Support\StoredFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a draft proposal's plan: every task raised on the proposal or on
 * one of its milestones, then the milestones (T31; called by DeleteProposal
 * inside its own transaction).
 *
 * Tasks are found without the visibility scope, so what is deleted never
 * depends on what the signed-in user can see. Everything goes through
 * Eloquent, subtasks before their parents and nested milestones before
 * theirs, so observers and the activity log run. Attachment files leave the
 * disk only once the outermost transaction has committed.
 */
final class DeleteProposalPlan
{
    public function handle(Proposal $proposal): void
    {
        Gate::authorize('delete', $proposal);

        DB::transaction(function () use ($proposal): void {
            $milestones = Milestone::query()->where('proposal_id', $proposal->id)->lockForUpdate()->get();

            $tasks = $this->planTasks($proposal->id, $milestones);
            $files = TaskAttachment::query()
                ->whereIn('task_id', $tasks->modelKeys())
                ->whereNotNull('original_name')
                ->get(['id', 'disk', 'path']);

            $tasks->sortByDesc(fn (Task $task): bool => $task->parent_task_id !== null)
                ->each(fn (Task $task) => $task->delete());

            $this->deleteMilestones($milestones);

            DB::afterCommit(fn () => $files->each(
                fn (TaskAttachment $file) => StoredFiles::forget($file->disk, $file->path, 'task attachment', ['task_attachment_id' => $file->id]),
            ));
        });
    }

    /**
     * The proposal's plan tasks, the same rows as Proposal::planTasks() but
     * unscoped, with any subtask hanging under one of them.
     *
     * @param  Collection<int, Milestone>  $milestones
     * @return Collection<int, Task>
     */
    private function planTasks(int $proposalId, Collection $milestones): Collection
    {
        $unscoped = fn (): Builder => Task::query()->withoutGlobalScope(TaskVisibilityScope::class);

        $tasks = $unscoped()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'proposal')->where('source_id', $proposalId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', $milestones->modelKeys())))
            ->lockForUpdate()
            ->get();

        $subtasks = $unscoped()
            ->whereIn('parent_task_id', $tasks->modelKeys())
            ->whereNotIn('id', $tasks->modelKeys())
            ->lockForUpdate()
            ->get();

        return $tasks->merge($subtasks);
    }

    /**
     * Delete the milestones deepest first, so each one's nested milestones
     * are already gone when it is deleted.
     *
     * @param  Collection<int, Milestone>  $milestones
     */
    private function deleteMilestones(Collection $milestones): void
    {
        $byId = $milestones->keyBy('id');

        $parentOf = fn (Milestone $milestone): ?Milestone => $milestone->parent_milestone_id === null
            ? null
            : $byId->get($milestone->parent_milestone_id);

        $depth = function (Milestone $milestone) use ($byId, $parentOf): int {
            $levels = 0;

            for ($parent = $parentOf($milestone); $parent !== null && $levels < $byId->count(); $parent = $parentOf($parent)) {
                $levels++;
            }

            return $levels;
        };

        $milestones->sortByDesc($depth)
            ->each(fn (Milestone $milestone) => $milestone->delete());
    }
}
