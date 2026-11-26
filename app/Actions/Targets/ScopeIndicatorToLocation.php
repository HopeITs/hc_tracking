<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Scope a program-framework indicator to one location row (Y-16, D-93).
 *
 * The row-keyed twin of ScopeIndicatorToProject, guard for guard and message
 * for message, so nothing a user sees changes:
 *
 * - Who: `indicators.update` holders.
 * - Scope (BOLA): only an indicator under a program-framework result;
 *   a proposal-framework instance is not found.
 * - The row must sit in the result's program, and the change must not put a
 *   committed target out of step (PlanIndicator::chainIssuesForRow(),
 *   refusing only the issues it introduces).
 * - One transaction with the indicator row locked; written through Eloquent,
 *   so the change is logged.
 *
 * `project_id` is left as it is: it is still written until Y-18, and the row
 * is what readers follow from here on.
 *
 * Error key: indicator.
 */
final class ScopeIndicatorToLocation
{
    /**
     * @throws ValidationException
     */
    public function handle(ProposalLocation $location, int $indicatorId): Indicator
    {
        Gate::authorize('indicators.update');

        return DB::transaction(function () use ($location, $indicatorId): Indicator {
            $indicator = Indicator::query()
                ->whereIn('result_id', Result::query()->programFramework()->select('id'))
                ->whereKey($indicatorId)
                ->lockForUpdate()
                ->with('result:id,program_id,proposal_id')
                ->firstOrFail();

            $programId = ProposalLocation::query()->whereKey($location->getKey())->value('program_id');

            if ($indicator->result->program_id === null || $programId === null || (int) $programId !== $indicator->result->program_id) {
                $this->refuse(__('indicators.this_project_does_not_deliver_the_result_program'));
            }

            if ($indicator->proposal_location_id === $location->id) {
                return $indicator;
            }

            $before = (clone $indicator)->setRelation('result', $indicator->result);
            $indicator->proposal_location_id = $location->id;

            $targets = PlanIndicator::query()
                ->where('indicator_id', $indicator->id)
                ->with('location:id,proposal_id,program_id')
                ->get()
                ->loadMorph('source', [Proposal::class => []]);

            foreach ($targets as $target) {
                $introduced = array_diff(
                    PlanIndicator::chainIssuesForRow($target->source, $indicator, $target->location),
                    PlanIndicator::chainIssuesForRow($target->source, $before, $target->location),
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
