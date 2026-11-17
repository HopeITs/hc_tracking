<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

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
 * Remove a draft proposal's plan: every task raised on the proposal (T31;
 * called by DeleteProposal inside its own transaction).
 *
 * Tasks are found without the visibility scope, so what is deleted never
 * depends on what the signed-in user can see. Everything goes through
 * Eloquent, subtasks before their parents, so observers and the activity log run. Attachment files leave the
 * disk only once the outermost transaction has committed.
 */
final class DeleteProposalPlan
{
    public function handle(Proposal $proposal): void
    {
        Gate::authorize('delete', $proposal);

        DB::transaction(function () use ($proposal): void {
            $tasks = $this->planTasks($proposal->id);
            $files = TaskAttachment::query()
                ->whereIn('task_id', $tasks->modelKeys())
                ->whereNotNull('original_name')
                ->get(['id', 'disk', 'path']);

            $tasks->sortByDesc(fn (Task $task): bool => $task->parent_task_id !== null)
                ->each(fn (Task $task) => $task->delete());

            DB::afterCommit(fn () => $files->each(
                fn (TaskAttachment $file) => StoredFiles::forget($file->disk, $file->path, 'task attachment', ['task_attachment_id' => $file->id]),
            ));
        });
    }

    /**
     * The proposal's plan tasks, the same rows as Proposal::planTasks() but
     * unscoped, with any subtask hanging under one of them.
     *
     * @return Collection<int, Task>
     */
    private function planTasks(int $proposalId): Collection
    {
        $unscoped = fn (): Builder => Task::query()->withoutGlobalScope(TaskVisibilityScope::class);

        $tasks = $unscoped()
            ->where('source_type', 'proposal')
            ->where('source_id', $proposalId)
            ->lockForUpdate()
            ->get();

        $subtasks = $unscoped()
            ->whereIn('parent_task_id', $tasks->modelKeys())
            ->whereNotIn('id', $tasks->modelKeys())
            ->lockForUpdate()
            ->get();

        return $tasks->merge($subtasks);
    }
}
