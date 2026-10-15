<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Scope a program-framework indicator to one project (T13, projects/show).
 *
 * - Who: `indicators.update` holders (the right that sets an indicator's
 *   project on the indicators page).
 * - Scope (BOLA): only an indicator under a program-framework result;
 *   a proposal-framework instance is not found.
 * - The project must deliver the result's program, and the change must not
 *   put a committed target out of step (PlanIndicator::chainIssuesFor(),
 *   refusing only the issues it introduces).
 * - One transaction with the indicator row locked; written through Eloquent,
 *   so the change is logged.
 *
 * Error key: indicator.
 */
final class ScopeIndicatorToProject
{
    /**
     * @throws ValidationException
     */
    public function handle(Project $project, int $indicatorId): Indicator
    {
        Gate::authorize('indicators.update');

        return DB::transaction(function () use ($project, $indicatorId): Indicator {
            $indicator = Indicator::query()
                ->whereIn('result_id', Result::query()->programFramework()->select('id'))
                ->whereKey($indicatorId)
                ->lockForUpdate()
                ->with('result:id,program_id,proposal_id')
                ->firstOrFail();

            $programId = Project::query()->whereKey($project->getKey())->value('program_id');

            if ($indicator->result->program_id === null || $programId === null || (int) $programId !== $indicator->result->program_id) {
                $this->refuse(__('indicators.this_project_does_not_deliver_the_result_program'));
            }

            if ($indicator->project_id === $project->id) {
                return $indicator;
            }

            $before = (clone $indicator)->setRelation('result', $indicator->result);
            $indicator->project_id = $project->id;

            $targets = PlanIndicator::query()
                ->where('indicator_id', $indicator->id)
                ->with('project:id,program_id')
                ->get()
                ->loadMorph('source', [Proposal::class => ['projects:id']]);

            foreach ($targets as $target) {
                $introduced = array_diff(
                    PlanIndicator::chainIssuesFor($target->source, $indicator, $target->project),
                    PlanIndicator::chainIssuesFor($target->source, $before, $target->project),
                );

                if ($introduced !== []) {
                    $issue = __(reset($introduced));

                    $this->refuse(__('indicators.this_change_would_break_a_committed_target', ['issue' => is_string($issue) ? $issue : '']));
                }
            }

            $indicator->save();

            return $indicator;
        });
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['indicator' => $message]);
    }
}
