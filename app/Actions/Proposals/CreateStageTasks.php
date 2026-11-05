<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Milestones\SaveProposalMilestone;
use App\Enums\ProposalStatus;
use App\Enums\StageTemplateCreates;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\ProposalStageTaskTemplate;
use App\Models\Task;
use App\Support\EmployeeProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Create the department work a proposal stage needs, from the templates the
 * PR Manager maintains (D-63, contracts.md §6).
 *
 * Nobody is assigned: a task names its department and waits for someone to
 * pick it up (D-33). The one exception is the final-report milestone, whose
 * `responsible_user_id` the database requires — it goes to the manager of
 * the template's own department (D-65: HR job level grade 5), so the person
 * is read from HR rather than invented.
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
     * @return list<int> the ids of the tasks and milestones created
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
     * One template, or null when it has nothing to add: it already ran, or
     * its department has no manager to carry the milestone.
     */
    private function applyTemplate(Proposal $proposal, ProposalStageTaskTemplate $template): ?int
    {
        if ($this->alreadyApplied($proposal, $template)) {
            return null;
        }

        return $template->creates === StageTemplateCreates::FinalReportMilestone
            ? $this->createFinalReportMilestone($proposal, $template)
            : $this->createTask($proposal, $template);
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
        if ($template->creates === StageTemplateCreates::FinalReportMilestone) {
            // A proposal has at most one final-report milestone whatever the
            // template says, and SaveProposalMilestone enforces that too.
            return Milestone::query()->where('proposal_id', $proposal->id)->where('is_final_report', true)->exists();
        }

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
     * The proposal's final-report milestone, through SaveProposalMilestone's
     * own path — the only place such a milestone is created — with the
     * template's department and that department's manager.
     *
     * Null when the department has no manager in HR: the template is logged
     * and skipped, and the approval it followed is untouched.
     */
    private function createFinalReportMilestone(Proposal $proposal, ProposalStageTaskTemplate $template): ?int
    {
        $responsible = EmployeeProfile::managerOfDepartment($template->department_id);

        if ($responsible === null) {
            Log::warning('Stage template skipped: the department has no manager in HR.', [
                'proposal_id' => $proposal->id,
                'template_id' => $template->id,
                'department_id' => $template->department_id,
            ]);

            return null;
        }

        $periodEnd = $this->proposalPeriodEnd($proposal);

        if ($periodEnd === null) {
            Log::warning('Stage template skipped: the proposal has no period to place the final report in.', [
                'proposal_id' => $proposal->id,
                'template_id' => $template->id,
            ]);

            return null;
        }

        // The final report is prepared at the end of the proposal, not at the
        // moment of approval: SaveProposalMilestone requires the milestone to
        // sit inside the proposal's period, and a report due before the work
        // finishes would be wrong anyway. The template's offset therefore
        // counts from the period's end, never earlier than today.
        $offsetEnd = $periodEnd->addDays($template->due_offset_days);
        $today = CarbonImmutable::today();
        $due = $offsetEnd->lessThan($today) ? $today : $offsetEnd;

        try {
            $milestone = app(SaveProposalMilestone::class)->addFinalReportFromTemplate($proposal, [
                'planned_start_date' => $periodEnd->toDateString(),
                'planned_end_date' => $periodEnd->toDateString(),
                'responsible_employee_id' => $responsible,
                'department_id' => $template->department_id,
                'due_date' => $due->toDateString(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Stage template could not open the final-report milestone.', [
                'proposal_id' => $proposal->id,
                'template_id' => $template->id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        DB::table('milestones')->where('id', $milestone->id)->update(['stage_template_id' => $template->id]);

        return (int) $milestone->id;
    }

    /**
     * The end of the proposal's period — its actual dates when it has them,
     * else its planned ones — which is what SaveProposalMilestone checks a
     * milestone against. Null when the proposal has neither pair.
     */
    private function proposalPeriodEnd(Proposal $proposal): ?CarbonImmutable
    {
        foreach (['actual_date_end', 'planned_date_end'] as $column) {
            $end = $proposal->getAttribute($column);

            if ($end instanceof CarbonImmutable) {
                return $end;
            }
        }

        return null;
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
