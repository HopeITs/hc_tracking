<?php

use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\MonthlyMatrixDemoSeeder;

test('the demo seeder fills the monthly matrix once, in other hands than the first user, and records no activity', function () {
    $this->travelTo('2026-11-23 10:00:00');

    [$indicator, $target, $firstUserId] = Activity::withoutRecording(function (): array {
        $firstUserId = User::factory()->create()->id;
        User::factory()->create();
        $program = Program::factory()->create();
        $proposal = Proposal::factory()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
        $result = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $program->id]);
        $indicator = Indicator::factory()->create(['result_id' => $result->id, 'measure' => IndicatorMeasure::Numeric, 'name' => 'Families reached']);
        $target = PlanIndicator::factory()->forProposal($proposal)->create([
            'indicator_id' => $indicator->id,
            'city_id' => insertHrCity(),
            'program_id' => $program->id,
            'target' => '1200',
        ]);

        return [$indicator, $target, $firstUserId];
    });
    $activityBefore = Activity::query()->count();

    $this->seed(MonthlyMatrixDemoSeeder::class);

    $template = ReportTemplate::query()->sole();
    $statuses = fn (): array => MonthlyReport::query()->where('report_template_id', $template->id)->orderBy('period')->pluck('status')->map->value->all();
    $first = $statuses();
    $mirrors = Report::query()->where('plan_indicator_id', $target->id)->count();

    $this->seed(MonthlyMatrixDemoSeeder::class);

    expect($indicator->fresh()->library_indicator_id)->not->toBeNull()
        ->and($template->proposals()->pluck('proposals.id')->all())->toBe([$target->source_id])
        ->and($first)->toBe([...array_fill(0, 9, MonthlyReportStatus::Validated->value), MonthlyReportStatus::Submitted->value, MonthlyReportStatus::Draft->value])
        ->and(Report::query()->where('plan_indicator_id', $target->id)->where('status', ReportStatus::Approved->value)->whereNotNull('value')->count())->toBe(9)
        ->and($statuses())->toBe($first)
        ->and(Report::query()->where('plan_indicator_id', $target->id)->count())->toBe($mirrors)
        ->and(MonthlyReport::query()->where('submitted_by', $firstUserId)->exists())->toBeFalse()
        ->and(Activity::query()->count())->toBe($activityBefore);
});
