<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\ActivitySchedule;
use App\Models\ProposalLocation;
use App\Support\PlanCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the month/week calendar (activity_schedules) of every activity in
 * an approved plan from its batches (user decision 2026-11-12): the batches
 * are the only stored dates, and the monthly report keeps reading the
 * calendar it always read, untouched.
 *
 * - A month with all its Mondays planned is stored as the whole month
 *   (weeks null); otherwise as the week numbers inside it.
 * - An activity running in every governorate of the plan writes the
 *   proposal calendar (no location). One running in some governorates
 *   writes a calendar for each of the plan's location rows in them (Y-15,
 *   D-93), and none for the proposal, so rows elsewhere see no month at all.
 *   Each row's `project_id` is still written until Y-18.
 */
final class SyncActivitySchedules
{
    public function handle(ActivityPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $plan->loadMissing(['proposal', 'items.batches', 'items.cities']);
            $locations = $this->planLocations($plan);
            $planCityIds = array_values(array_unique(array_filter(array_map(
                fn (ProposalLocation $location): int => $location->city_id,
                $locations,
            ))));

            foreach ($plan->items as $item) {
                ActivitySchedule::query()->where('result_id', $item->result_id)->delete();
                $calendar = self::calendarOf($item);

                if ($calendar === []) {
                    continue;
                }

                $itemCityIds = $item->cityIds();
                $everywhere = array_diff($planCityIds, $itemCityIds) === [];
                $targets = $everywhere ? [null] : array_values(array_filter(
                    $locations,
                    fn (ProposalLocation $location): bool => in_array($location->city_id, $itemCityIds, true),
                ));

                foreach ($targets as $location) {
                    foreach ($calendar as $month => $weeks) {
                        ActivitySchedule::query()->create([
                            'result_id' => $item->result_id,
                            'proposal_location_id' => $location?->id,
                            'project_id' => $location?->project_id,
                            'month' => $month.'-01',
                            'weeks' => $weeks,
                        ]);
                    }
                }
            }
        });
    }

    /**
     * The month/week calendar of one item: month => week numbers, or null
     * when every Monday of the month is planned.
     *
     * @return array<string, list<int>|null>
     */
    public static function calendarOf(ActivityPlanItem $item): array
    {
        $byMonth = [];

        foreach ($item->batches as $batch) {
            /** @var ActivityPlanBatch $batch */
            foreach ($batch->mondays() as $monday) {
                $byMonth[$monday->format('Y-m')][PlanCalendar::weekOfMonth($monday)] = true;
            }
        }

        ksort($byMonth);
        $calendar = [];

        foreach ($byMonth as $month => $weeks) {
            $numbers = array_keys($weeks);
            sort($numbers);
            $calendar[$month] = count($numbers) === self::mondaysInMonth($month) ? null : $numbers;
        }

        return $calendar;
    }

    private static function mondaysInMonth(string $month): int
    {
        $day = CarbonImmutable::createFromFormat('!Y-m', $month);
        $count = 0;

        for ($date = $day; $date->format('Y-m') === $month; $date = $date->addDay()) {
            if ($date->isMonday()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The location rows of the plan's proposal for the plan's program (one
     * per city, D-93).
     *
     * @return list<ProposalLocation>
     */
    private function planLocations(ActivityPlan $plan): array
    {
        return array_values($plan->proposal->locations()
            ->where('program_id', $plan->program_id)
            ->orderBy('id')
            ->get(['id', 'proposal_id', 'city_id', 'project_id'])
            ->all());
    }
}
