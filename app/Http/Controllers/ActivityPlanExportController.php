<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CountUnit;
use App\Models\ActivityPlan;
use App\Support\ActivityPlanScope;
use App\Support\CsvExport;
use App\Support\MealCoverage;
use App\Support\PlanExecution;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityPlanExportController extends Controller
{
    /**
     * The whole plan for the donor (2026-11-11: print and Excel of the
     * expected and the execution plan): `pdf` is a plain page for the
     * browser's print-to-PDF, `xlsx` an Excel-readable CSV. Part one is the
     * plan (each batch with its weeks, count, hours and places); part two is
     * the execution per centre (status, units held, units planned so far).
     */
    public function __invoke(Request $request, ActivityPlan $activityPlan): View|StreamedResponse
    {
        $execution = PlanExecution::for($activityPlan);
        $cityNames = ActivityPlanScope::cityNames(ActivityPlanScope::cityIds($activityPlan));

        if ($request->query('format') !== 'xlsx') {
            return view('pages.annual-plans._plan-print', ['plan' => $activityPlan, 'execution' => $execution, 'cityNames' => $cityNames]);
        }

        $rows = function () use ($activityPlan, $execution, $cityNames): iterable {
            foreach ($activityPlan->items as $item) {
                foreach ($item->batches->values() as $index => $batch) {
                    yield [
                        (string) $item->activity?->statement,
                        $item->is_single_week ? __('annual_plans.wf.week_n', ['n' => $index + 1]) : __('annual_plans.wf.batch_n', ['n' => $index + 1]),
                        $batch->start_week->toDateString(),
                        $batch->end_week->toDateString(),
                        (int) $batch->planned_count,
                        $item->unit === CountUnit::Other ? (string) $item->unit_other : $item->unit->label(),
                        $batch->hours_each === null ? '' : (string) (float) $batch->hours_each,
                        implode(', ', array_values(array_intersect_key($cityNames, array_flip($item->cityIds())))),
                        $item->expected_people,
                    ];
                }
            }

            if ($execution->projects() === []) {
                return;
            }

            yield [];
            yield [__('annual_plans.wf.activity'), __('annual_plans.up.col_centre'), __('annual_plans.up.col_status'), __('annual_plans.export.held'), __('annual_plans.export.planned_so_far'), __('annual_plans.export.planned_total')];

            foreach ($activityPlan->items as $item) {
                foreach ($execution->projects() as $centre) {
                    if (! in_array(MealCoverage::cityIdOf($centre), $item->cityIds(), true)) {
                        continue;
                    }

                    $status = $execution->status($centre, $item);

                    yield [
                        (string) $item->activity?->statement,
                        $centre->name,
                        $status['key'] === 'late' ? __('annual_plans.live.late_weeks', ['weeks' => $status['weeks']]) : __('annual_plans.live.status_'.$status['key']),
                        $execution->held($centre, $item),
                        $execution->plannedToDate($centre, $item),
                        (int) $item->batches->sum('planned_count'),
                    ];
                }
            }
        };

        return CsvExport::download(
            str($activityPlan->slug)->slug().'-plan.csv',
            [__('annual_plans.wf.activity'), __('annual_plans.export.batch'), __('annual_plans.wf.start_week'), __('annual_plans.wf.end_week'), __('annual_plans.export.count'), __('annual_plans.wf.what_you_count'), __('annual_plans.wf.hours_each'), __('annual_plans.wf.fact_governorates'), __('annual_plans.wf.expected_people')],
            $rows(),
        );
    }
}
