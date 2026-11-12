<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\Project;
use App\Policies\PlanUpdatePolicy;
use App\Support\CsvExport;
use App\Support\PlanExecution;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanUpdateExportController extends Controller
{
    /**
     * A monthly update for the donor: `pdf` is a plain page for the
     * browser's print-to-PDF, `xlsx` an Excel-readable CSV of both parts.
     */
    public function __invoke(Request $request, ActivityPlan $activityPlan, Project $project, string $month): View|StreamedResponse
    {
        abort_unless(PlanUpdatePolicy::canRead($request->user()), 403);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);

        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $update = PlanUpdate::query()
            ->where('activity_plan_id', $activityPlan->id)->where('project_id', $project->id)->where('month', $monthStart->toDateString())
            ->with(['weeks', 'numbers', 'files', 'questions.user'])
            ->firstOrFail();
        $execution = PlanExecution::for($activityPlan);
        $rows = $execution->batchesFor($project, $monthStart);

        if ($request->query('format') !== 'xlsx') {
            return view('pages.annual-plans._update-print', compact('activityPlan', 'project', 'monthStart', 'update', 'execution', 'rows'));
        }

        $lines = function () use ($update, $rows, $execution, $monthStart): iterable {
            foreach ($rows as $row) {
                foreach ($execution->calendar->mondaysOf($monthStart) as $monday) {
                    $week = $update->weeks->first(fn ($entry): bool => $entry->activity_plan_batch_id === $row['batch']->id && $entry->week->equalTo($monday));

                    if ($week !== null) {
                        yield [(string) $row['item']->activity?->statement, $row['index'] + 1, $monday->toDateString(), $week->status->label(), (string) $week->reason, $week->new_week?->toDateString()];
                    }
                }
            }

            yield [];
            yield [__('annual_plans.up.col_batch'), '', __('annual_plans.up.col_planned_batch'), __('annual_plans.up.col_done', ['month' => $monthStart->translatedFormat('F')])];

            foreach ($rows as $row) {
                yield [(string) $row['item']->activity?->statement, $row['index'] + 1, (int) $row['batch']->planned_count, (int) ($update->numbers->firstWhere('activity_plan_batch_id', $row['batch']->id)?->done_count ?? 0)];
            }

            yield [];
            yield [__('annual_plans.up.male'), __('annual_plans.up.female'), __('annual_plans.up.total'), __('annual_plans.up.with_disability')];
            yield [(int) $update->males, (int) $update->females, (int) $update->males + (int) $update->females, (int) $update->with_disability];
            yield [];

            foreach (['activities_implemented' => 'done', 'challenges' => 'challenges', 'next_month' => 'next', 'accountability' => 'accountability'] as $field => $key) {
                yield [__('annual_plans.up.section_'.$key), (string) $update->{$field}];
            }
        };

        return CsvExport::download(
            str($project->name.'-'.$monthStart->format('Y-m'))->slug().'.csv',
            [__('annual_plans.wf.activity'), __('annual_plans.wf.batch_n', ['n' => '']), __('annual_plans.up.col_week'), __('annual_plans.up.col_status'), __('annual_plans.up.col_reason'), __('annual_plans.up.new_week')],
            $lines(),
        );
    }
}
