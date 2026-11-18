<?php

use App\Enums\DenominatorBasis;
use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Support\MonthlyReportSharedTargets;
use App\Support\Reporting\ReportPlace;
use Illuminate\Support\Facades\DB;

/*
| D-90: a target shared by the centers of its row is a line in each center's
| report. Each center enters its own figure; the report reads, next to it,
| how many centers share the target and what they reached together from
| validated figures only.
*/

beforeEach(function () {
    Activity::withoutRecording(function (): void {
        $this->program = Program::factory()->create();
        $this->proposal = Proposal::factory()->create();
        $row = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id, 'city_id' => 7, 'program_id' => $this->program->id]);
        $row->centers()->create(['center_id' => 9]);
        $row->centers()->create(['center_id' => 10]);

        $this->shared = PlanIndicator::factory()->forProposal($this->proposal)
            ->create(['project_id' => null, 'city_id' => 7, 'program_id' => $this->program->id, 'all_centers' => true, 'target' => 100]);
        $this->own = PlanIndicator::factory()->forProposal($this->proposal)
            ->create(['project_id' => null, 'city_id' => 7, 'program_id' => $this->program->id, 'center_id' => 9, 'target' => 50]);

        $this->reportA = mrstReport(new ReportPlace(7, $this->program->id, 9, false), [$this->shared, $this->own]);
        $this->reportB = mrstReport(new ReportPlace(7, $this->program->id, 10, false), [$this->shared]);
    });
});

/**
 * A report of a template at the place with one line and one share per target.
 *
 * @param  list<PlanIndicator>  $targets
 */
function mrstReport(ReportPlace $place, array $targets): MonthlyReport
{
    $template = ReportTemplate::factory()->confirmed()->forPlace($place, test()->proposal)->create();
    $report = MonthlyReport::factory()->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => '2026-10-01']);

    foreach ($targets as $target) {
        $line = MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id]);
        MonthlyReportLineShare::factory()->create(['monthly_report_line_id' => $line->id, 'plan_indicator_id' => $target->id]);
    }

    return $report;
}

test('a shared target is read in each center report with its centers and what they reached together', function () {
    Activity::withoutRecording(function (): void {
        Report::factory()->approved()->create(['plan_indicator_id' => $this->shared->id, 'value' => 30]);
        Report::factory()->approved()->create(['plan_indicator_id' => $this->shared->id, 'value' => 12]);
        Report::factory()->reviewed()->create(['plan_indicator_id' => $this->shared->id, 'value' => 500]);
        Report::factory()->create(['plan_indicator_id' => $this->shared->id, 'value' => 700]);
    });

    $a = app(MonthlyReportSharedTargets::class)->for($this->reportA);
    $b = app(MonthlyReportSharedTargets::class)->for($this->reportB);

    expect($a)->toBe([$this->shared->id => ['centers' => 2, 'target' => 100.0, 'reached' => 42.0]])
        ->and($b)->toBe($a);
});

test('a shared target nobody has validated yet has reached nothing', function () {
    Activity::withoutRecording(fn () => Report::factory()->reviewed()->create(['plan_indicator_id' => $this->shared->id, 'value' => 5]));

    expect(app(MonthlyReportSharedTargets::class)->for($this->reportB))
        ->toBe([$this->shared->id => ['centers' => 2, 'target' => 100.0, 'reached' => null]]);
});

test('a report without a shared target reads nothing', function () {
    $report = Activity::withoutRecording(fn (): MonthlyReport => mrstReport(new ReportPlace(7, $this->program->id, 9, false), [$this->own]));

    expect(app(MonthlyReportSharedTargets::class)->for($report))->toBe([]);
});

test('the read takes a fixed number of queries whatever the number of lines', function () {
    $count = function (MonthlyReport $report): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MonthlyReportSharedTargets::class)->for($report);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count($this->reportB);

    Activity::withoutRecording(function (): void {
        foreach (range(1, 3) as $ignored) {
            $target = PlanIndicator::factory()->forProposal($this->proposal)
                ->create(['project_id' => null, 'city_id' => 7, 'program_id' => $this->program->id, 'all_centers' => true]);
            $line = MonthlyReportLine::factory()->create(['monthly_report_id' => $this->reportB->id]);
            MonthlyReportLineShare::factory()->create(['monthly_report_line_id' => $line->id, 'plan_indicator_id' => $target->id]);
            Report::factory()->approved()->create(['plan_indicator_id' => $target->id, 'value' => 1]);
        }
    });

    expect($count($this->reportB->fresh()))->toBe($few);
});

/**
 * A shared percentage target, entered as counts (Dev 1, 18 Nov), with one
 * mirror per center report.
 *
 * @param  list<array{0: MonthlyReport, 1: float, 2: float, 3?: ReportStatus}>  $figures
 */
function mrstRatioTarget(array $figures): PlanIndicator
{
    return Activity::withoutRecording(function () use ($figures): PlanIndicator {
        $entry = LibraryIndicator::factory()->create(['measure' => IndicatorMeasure::Percentage, 'denominator_basis' => DenominatorBasis::Entered]);
        $indicator = Indicator::factory()->create(['library_indicator_id' => $entry->id, 'measure' => IndicatorMeasure::Percentage]);
        $target = PlanIndicator::factory()->forProposal(test()->proposal)
            ->create(['indicator_id' => $indicator->id, 'project_id' => null, 'city_id' => 7, 'program_id' => test()->program->id, 'all_centers' => true, 'target' => 90]);

        foreach ($figures as $figure) {
            $line = MonthlyReportLine::factory()->create(['monthly_report_id' => $figure[0]->id]);
            MonthlyReportLineShare::factory()->create(['monthly_report_line_id' => $line->id, 'plan_indicator_id' => $target->id]);
            Report::factory()->create([
                'plan_indicator_id' => $target->id,
                'monthly_report_id' => $figure[0]->id,
                'status' => $figure[3] ?? ReportStatus::Approved,
                'planned_date' => '2026-10-31',
                'numerator' => $figure[1],
                'denominator' => $figure[2],
                'value' => round($figure[1] / $figure[2] * 100, 2),
            ]);
        }

        return $target;
    });
}

test('a shared ratio target pools the centers: the sum of numerators over the sum of denominators', function () {
    $target = mrstRatioTarget([[$this->reportA, 45, 50], [$this->reportB, 5, 10]]);

    $read = app(MonthlyReportSharedTargets::class)->for($this->reportA)[$target->id];

    // 50 / 60, not the average of 90% and 50% (70%) and not the latest center (50%).
    expect($read['centers'])->toBe(2)
        ->and(round($read['reached'], 1))->toBe(83.3);
});

test('a center not validated yet stays out of the pooled ratio', function () {
    $target = mrstRatioTarget([[$this->reportA, 45, 50], [$this->reportB, 5, 10, ReportStatus::Reviewed]]);

    expect(app(MonthlyReportSharedTargets::class)->for($this->reportB)[$target->id]['reached'])->toBe(90.0);
});
