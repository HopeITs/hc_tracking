<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\HrCity;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use Illuminate\Support\Collection;

/**
 * What one annual plan covers: the centres of its program in its proposal
 * (the proposal's location rows for that program, Y-15, D-93), their
 * governorates, and the logframe activities it may plan,
 * grouped by outcome.
 */
final class ActivityPlanScope
{
    /**
     * The proposal's centres of the plan's program: its location rows for
     * that program, one per city, with what label() reads loaded, by label.
     *
     * @return list<ProposalLocation>
     */
    public static function locations(ActivityPlan $plan): array
    {
        return array_values(ProposalLocation::query()
            ->where('proposal_id', $plan->proposal_id)
            ->where('program_id', $plan->program_id)
            ->with(['city', 'program', 'centers'])
            ->get()
            ->sortBy(fn (ProposalLocation $location): string => $location->label(), SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /**
     * The programs a plan can be made for in a proposal: those of its
     * location rows, ascending (Y-15: a plan's centres are those rows).
     *
     * @return list<int>
     */
    public static function programIdsOf(Proposal $proposal): array
    {
        return array_values(ProposalLocation::query()
            ->where('proposal_id', $proposal->id)
            ->whereNotNull('program_id')
            ->distinct()
            ->orderBy('program_id')
            ->pluck('program_id')
            ->map(fn (int|string $programId): int => (int) $programId)
            ->all());
    }

    /**
     * The governorates of those centres, ascending.
     *
     * @param  list<ProposalLocation>|null  $locations
     * @return list<int>
     */
    public static function cityIds(ActivityPlan $plan, ?array $locations = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (ProposalLocation $location): int => $location->city_id,
            $locations ?? self::locations($plan),
        ))));
        sort($ids);

        return $ids;
    }

    /**
     * City id => name in the current language.
     *
     * @param  list<int>  $cityIds
     * @return array<int, string>
     */
    public static function cityNames(array $cityIds): array
    {
        if ($cityIds === []) {
            return [];
        }

        $arabic = app()->getLocale() === 'ar';

        return HrCity::query()->whereKey($cityIds)->get(['id', 'name_en', 'name_ar'])
            ->mapWithKeys(fn (HrCity $city): array => [(int) $city->id => trim((string) ($arabic ? ($city->name_ar ?: $city->name_en) : ($city->name_en ?: $city->name_ar)))])
            ->sortBy(fn (string $name, int $id): int => array_search($id, $cityIds, true))
            ->all();
    }

    /**
     * The plan's program's logframe activities in the proposal, grouped
     * under their outcome (or output when there is no outcome above it).
     *
     * @return list<array{outcome: string, activities: list<Result>}>
     */
    public static function activityGroups(ActivityPlan $plan): array
    {
        /** @var Collection<int, Result> $results */
        $results = Result::query()
            ->where('proposal_id', $plan->proposal_id)
            ->get(['id', 'statement', 'parent_id', 'program_id', 'type', 'activity_kind']);
        $byId = $results->keyBy('id');
        $groups = [];

        foreach ($results->where('type', ResultType::Activity)->sortBy('id') as $activity) {
            if ($activity->program_id !== null && (int) $activity->program_id !== $plan->program_id) {
                continue;
            }

            $outcome = null;

            for ($node = $byId->get($activity->parent_id); $node !== null; $node = $byId->get($node->parent_id)) {
                if ($node->type === ResultType::Outcome) {
                    $outcome = $node;

                    break;
                }

                $outcome ??= $node;
            }

            $key = $outcome?->id ?? 0;
            $groups[$key] ??= ['outcome' => (string) ($outcome?->statement ?? __('annual_plans.no_outcome')), 'activities' => []];
            $groups[$key]['activities'][] = $activity;
        }

        return array_values($groups);
    }

    /**
     * The ids of the activities the plan may contain.
     *
     * @return list<int>
     */
    public static function activityIds(ActivityPlan $plan): array
    {
        $ids = [];

        foreach (self::activityGroups($plan) as $group) {
            foreach ($group['activities'] as $activity) {
                $ids[] = $activity->id;
            }
        }

        return $ids;
    }
}
