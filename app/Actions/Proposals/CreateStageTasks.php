<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStageTaskTemplate;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Create the department work a proposal stage needs, from the templates the
 * PR Manager maintains (D-63, contracts.md §6).
 *
 * Nobody is assigned: a task names its department and waits for someone to
 * pick it up (D-33). The final report is a task like any other: the legacy
 * milestones it once opened are gone.
 *
 * Idempotent on the pair (proposal, template): a template that already made
 * its row is skipped, so a replayed event, a retried job or a second move
 * into the same status creates nothing. A template marked
 * `repeat_on_reentry` is the deliberate exception and fires again once its
 * previous task is closed.
 *
 * Called from the queued CreateStageTasksOnStatusChanged listener, never
 * from inside the status transaction.
 */
final class CreateStageTasks
{
    /**
     * @return list<int> the ids of the tasks created
     */
    public function handle(Proposal $proposal, ProposalStatus $status): array
    {
        $templates = ProposalStageTaskTemplate::query()->forStatus($status)->get();

        if ($templates->isEmpty()) {
            return [];
        }

        $created = [];

        foreach ($templates as $template) {
            $id = $this->applyTemplate($proposal, $template);

            if ($id !== null) {
                $created[] = $id;
            }
        }

        return $created;
    }

    /**
     * One template, or null when it already ran.
     */
    private function applyTemplate(Proposal $proposal, ProposalStageTaskTemplate $template): ?int
    {
        if ($this->alreadyApplied($proposal, $template)) {
            return null;
        }

        return $this->createTask($proposal, $template);
    }

    /**
     * Whether this template has already produced its row for this proposal.
     *
     * `repeat_on_reentry` narrows the question to a row that is still open:
     * the template may run again once the last one is done, but never twice
     * over the same open task.
     */
    private function alreadyApplied(Proposal $proposal, ProposalStageTaskTemplate $template): bool
    {
        $tasks = Task::query()
            ->withoutGlobalScopes()
            ->where('source_type', 'proposal')
            ->where('source_id', $proposal->id)
            ->where('stage_template_id', $template->id);

        if ($template->repeat_on_reentry) {
            $tasks->whereNull('completed_date');
        }

        return $tasks->exists();
    }

    /**
     * A department task on the proposal, with no assignee (D-33).
     */
    private function createTask(Proposal $proposal, ProposalStageTaskTemplate $template): int
    {
        $task = Task::query()->create([
            'source_type' => 'proposal',
            'source_id' => $proposal->id,
            'department_id' => $template->department_id,
            'kind' => $template->kind,
            'stage_template_id' => $template->id,
            'title' => $template->title,
            'description' => $template->description,
            'requested_on' => CarbonImmutable::today()->toDateString(),
            'due_date' => $this->dueDate($proposal, $template),
            'created_by' => Auth::id(),
        ]);

        return (int) $task->id;
    }

    /**
     * The template's offset from the date the move took effect, which is the
     * date on the status note the transition wrote; today when there is none.
     */
    private function dueDate(Proposal $proposal, ProposalStageTaskTemplate $template): string
    {
        $effective = $proposal->statusNotes()->latest('id')->value('date');

        $from = $effective === null
            ? CarbonImmutable::today()
            : CarbonImmutable::parse(is_string($effective) ? $effective : $effective->toDateString());

        return $from->addDays($template->due_offset_days)->toDateString();
    }
}
