<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\FollowUpTrigger;
use App\Models\ActivityPlanFollowUp;
use App\Models\ActivityPlanItem;
use App\Models\PlanUpdate;
use App\Models\Project;
use App\Models\Task;
use App\Support\MealCoverage;
use App\Support\PlanExecution;
use App\Support\PlanNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Turns MEAL's follow-up tasks into real tasks when an activity reaches the
 * chosen point in a centre (Rami, 2026-11-11): when it starts (a week ran),
 * in the middle (its first batch ran at or past its middle week), or when it
 * finishes (done there). Each fires once per centre, as a task on the centre
 * for its governorate's MEAL officer, due in two weeks, closed by uploading
 * the result (evidence required). Runs after each update is sent.
 */
final class FireActivityPlanFollowUps
{
    public function handle(PlanUpdate $update): void
    {
        $update->loadMissing(['plan.followUps.item', 'project']);
        $plan = $update->plan;
        $project = $update->project;

        if ($plan->followUps->isEmpty()) {
            return;
        }

        $execution = PlanExecution::for($plan->fresh());
        $officer = MealCoverage::responsibleFor($project);

        foreach ($plan->followUps as $followUp) {
            $item = $execution->plan->items->firstWhere('id', $followUp->activity_plan_item_id);

            if ($item === null || ! in_array(MealCoverage::cityIdOf($project), $item->cityIds(), true) || ! $this->reached($execution, $followUp, $item, $project)) {
                continue;
            }

            if (DB::table('activity_plan_follow_up_tasks')->where('activity_plan_follow_up_id', $followUp->id)->where('project_id', $project->id)->exists()) {
                continue;
            }

            DB::transaction(function () use ($followUp, $item, $project, $officer, $plan, $update): void {
                $task = Task::query()->create([
                    'source_type' => 'project',
                    'source_id' => $project->id,
                    'title' => $followUp->description.' · '.$item->activity?->statement,
                    'description' => __('annual_plans.tasks.description', ['activity' => (string) $item->activity?->statement, 'centre' => $project->name, 'when' => $followUp->trigger->label()]),
                    'due_date' => now()->addWeeks(2)->toDateString(),
                    'start_date' => now()->toDateString(),
                    'evidence_required' => true,
                    'created_by' => $plan->approved_by ?? $followUp->created_by,
                ]);

                if ($officer !== null) {
                    $task->taskAssignees()->create(['employee_id' => $officer]);
                }

                DB::table('activity_plan_follow_up_tasks')->insert([
                    'activity_plan_follow_up_id' => $followUp->id,
                    'project_id' => $project->id,
                    'task_id' => $task->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                PlanNotifier::update($update, 'annual_plan.follow_up_task', ['officer', 'meal'], ['task' => $followUp->description, 'activity' => (string) $item->activity?->statement]);
            });
        }
    }

    private function reached(PlanExecution $execution, ActivityPlanFollowUp $followUp, ActivityPlanItem $item, Project $project): bool
    {
        $entries = collect($execution->entriesFor($project, $item));

        return match ($followUp->trigger) {
            FollowUpTrigger::Start => $entries->contains(fn (array $row): bool => $row['entry']->status->isActive()),
            FollowUpTrigger::Finish => $execution->status($project, $item)['key'] === 'done',
            FollowUpTrigger::Middle => ($first = $item->batches->first()) !== null && $entries->contains(fn (array $row): bool => $row['batch'] === $first->id
                && $row['entry']->status->isActive()
                && $row['week']->gte($first->start_week->addWeeks(intdiv(intdiv((int) $first->start_week->diffInDays($first->end_week), 7), 2)))),
        };
    }
}
