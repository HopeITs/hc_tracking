<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\ActivitySchedule;
use App\Models\Project;
use App\Support\MealCoverage;
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
 *   proposal calendar (project_id null). One running in some governorates
 *   writes a calendar for each of the plan's centres in them, and none for
 *   the proposal, so centres elsewhere see no month at all.
 */
final class SyncActivitySchedules
{
    public function handle(ActivityPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $plan->loadMissing(['proposal', 'items.batches', 'items.cities']);
            $projects = $this->planProjects($plan);
            $planCityIds = array_values(array_unique(array_filter(array_map(
                fn (Project $project): ?int => MealCoverage::cityIdOf($project),
                $projects,
            ))));

            foreach ($plan->items as $item) {
                ActivitySchedule::query()->where('result_id', $item->result_id)->delete();
                $calendar = self::calendarOf($item);

                if ($calendar === []) {
                    continue;
                }

                $itemCityIds = $item->cityIds();
                $everywhere = array_diff($planCityIds, $itemCityIds) === [];
                $projectIds = $everywhere ? [null] : array_values(array_map(
                    fn (Project $project): int => $project->id,
                    array_filter($projects, fn (Project $project): bool => in_array(MealCoverage::cityIdOf($project), $itemCityIds, true)),
                ));

                foreach ($projectIds as $projectId) {
                    foreach ($calendar as $month => $weeks) {
                        ActivitySchedule::query()->create([
                            'result_id' => $item->result_id,
                            'project_id' => $projectId,
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
     * The proposal's centres (projects) of the plan's program.
     *
     * @return list<Project>
     */
    private function planProjects(ActivityPlan $plan): array
    {
        return array_values($plan->proposal->projects()
            ->where('projects.program_id', $plan->program_id)
            ->get(['projects.id', 'projects.city_id', 'projects.center_id'])
            ->all());
    }
}
