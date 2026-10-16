<?php

use App\Enums\CollectionFrequency;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| The lines of a monthly report (contracts §13 "Lines of month M", §13.1
| items 1–2, D-38, D-46, D-48): confirmed and due template lines inside
| their target's window, one line per library indicator with one share per
| target, and MonthlyReportPeriods' months, sequencing and due dates.
*/

beforeEach(fn () => $this->travelTo('2026-10-16 10:00:00'));

/**
 * An active project (Nov 2026 – Dec 2027) in a program of the given
 * attribution mode, a donor-approved proposal over the same period and a
 * confirmed template.
 *
 * @return array{project: Project, proposal: Proposal, template: ReportTemplate}
 */
function mrlFixture(DonorAttribution $attribution = DonorAttribution::Shared): array
{
    return Activity::withoutRecording(function () use ($attribution): array {
        $program = Program::factory()->create(['donor_attribution' => $attribution]);
        $project = Project::factory()->active()->create([
            'program_id' => $program->id,
            'start_date' => '2026-11-01',
            'end_date' => '2027-12-31',
        ]);
        $proposal = mrlProposal($project);
        $template = ReportTemplate::factory()->confirmed()->create(['project_id' => $project->id]);

        return ['project' => $project, 'proposal' => $proposal, 'template' => $template];
    });
}

/**
 * A donor-approved proposal funding the project over the given dates.
 */
function mrlProposal(Project $project, string $begin = '2026-11-01', string $end = '2027-12-31', int $beneficiaries = 100): Proposal
{
    return Activity::withoutRecording(function () use ($project, $begin, $end, $beneficiaries): Proposal {
        $proposal = Proposal::factory()->create([
            'status' => ProposalStatus::Active,
            'actual_date_begin' => $begin,
            'actual_date_end' => $end,
        ]);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => $beneficiaries]);

        return $proposal;
    });
}

/**
 * A confirmed template line (Nov 2026 – Dec 2027) for a new target of the
 * proposal on the given library entry, monthly unless the attributes say
 * otherwise. Pass 'status' => 'pending' or 'retired' to change its status.
 *
 * @param  array<string, mixed>  $attributes
 */
function mrlLine(ReportTemplate $template, Proposal $proposal, ?LibraryIndicator $library = null, array $attributes = []): ReportTemplateLine
{
    return Activity::withoutRecording(function () use ($template, $proposal, $library, $attributes): ReportTemplateLine {
        $library ??= LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Monthly]);
        $indicator = Indicator::factory()->create(['library_indicator_id' => $library->id]);
        $target = PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $template->project_id]);
        $status = $attributes['status'] ?? 'confirmed';
        unset($attributes['status']);

        $line = ReportTemplateLine::factory()->confirmed()->create([
            'report_template_id' => $template->id,
            'plan_indicator_id' => $target->id,
            'schedule' => 'library_default',
            'effective_from' => '2026-11-01',
            'effective_to' => '2027-12-01',
            ...$attributes,
        ]);

        $line->forceFill(['status' => $status])->save();

        return $line;
    });
}

function mrlReport(Project $project, string $month, MonthlyReportStatus $status = MonthlyReportStatus::Draft): MonthlyReport
{
    return Activity::withoutRecording(fn (): MonthlyReport => MonthlyReport::factory()->create([
        'project_id' => $project->id,
        'period' => CarbonImmutable::parse($month)->startOfMonth()->toDateString(),
        'status' => $status,
    ]));
}

/**
 * @return array{lines: int, shares: int}
 */
function mrlBuild(MonthlyReport $report): array
{
    return app(MonthlyReportLineBuilder::class)->build($report);
}

/**
 * The months (Y-m) between Nov 2026 and Dec 2027 whose draft gets a line.
 *
 * @return list<string>
 */
function mrlMonthsWithLines(Project $project): array
{
    $months = [];

    for ($month = CarbonImmutable::parse('2026-11-01'); $month->lessThanOrEqualTo(CarbonImmutable::parse('2027-12-01')); $month = $month->addMonth()) {
        if (mrlBuild(mrlReport($project, $month->toDateString()))['lines'] > 0) {
            $months[] = $month->format('Y-m');
        }
    }

    return $months;
}

test('a monthly line reaches every month of its window', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, attributes: ['effective_to' => '2027-02-01']);

    expect(mrlMonthsWithLines($project))->toBe(['2026-11', '2026-12', '2027-01', '2027-02']);
});

test('a quarterly line is due every third month counted from its first month', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Quarterly]));

    expect(mrlMonthsWithLines($project))->toBe(['2027-01', '2027-04', '2027-07', '2027-10']);
});

test('a 12-month follow-up line is due in month 13 only', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::FollowUp, 'follow_up_months' => 12]));

    expect(mrlMonthsWithLines($project))->toBe(['2027-11']);
});

test('pending and retired lines, and targets whose window does not cover the month, give no line', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, attributes: ['status' => 'pending']);
    mrlLine($template, $proposal, attributes: ['status' => 'retired']);
    mrlLine($template, mrlProposal($project, '2027-06-01', '2027-12-31'));

    expect(mrlBuild(mrlReport($project, '2026-12-01')))->toBe(['lines' => 0, 'shares' => 0])
        ->and(MonthlyReportLine::query()->count())->toBe(0);
});

test('targets of one library indicator share one line, with one share per target, in both attribution modes', function (DonorAttribution $attribution, int $lineDenominator) {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture($attribution);
    $library = LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Monthly]);
    $first = mrlLine($template, $proposal, $library, ['fixed_denominator' => 100]);
    $second = mrlLine($template, mrlProposal($project, beneficiaries: 60), $library, ['fixed_denominator' => 60]);
    mrlLine($template, $proposal);

    $built = mrlBuild($report = mrlReport($project, '2026-12-01'));

    $line = $report->lines()->where('library_indicator_id', $library->id)->sole();
    $shares = $line->shares()->orderBy('id')->get();

    expect($built)->toBe(['lines' => 2, 'shares' => 3])
        ->and($line->fixed_denominator)->toBe($lineDenominator)
        ->and($line->entered_by)->toBe(IndicatorCollector::ProjectTeam)
        ->and($shares->pluck('plan_indicator_id')->all())->toBe([$first->plan_indicator_id, $second->plan_indicator_id])
        ->and($shares->pluck('fixed_denominator')->all())->toBe([100, 60]);
})->with([
    'shared: the highest' => [DonorAttribution::Shared, 100],
    'split: the sum' => [DonorAttribution::Split, 160],
]);

test('a line is entered by MEAL when any of its template lines is', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    $mixed = LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Monthly]);
    mrlLine($template, $proposal, $mixed, ['entered_by' => IndicatorCollector::ProjectTeam]);
    mrlLine($template, mrlProposal($project), $mixed, ['entered_by' => IndicatorCollector::Meal]);
    $team = mrlLine($template, $proposal, attributes: ['entered_by' => IndicatorCollector::ProjectTeam]);

    mrlBuild($report = mrlReport($project, '2026-12-01'));

    expect($report->lines()->where('library_indicator_id', $mixed->id)->sole()->entered_by)->toBe(IndicatorCollector::Meal)
        ->and($report->lines()->where('library_indicator_id', $team->planIndicator->indicator->library_indicator_id)->sole()->entered_by)->toBe(IndicatorCollector::ProjectTeam);
});

test('building again adds nothing', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal);
    $report = mrlReport($project, '2026-12-01');

    expect(mrlBuild($report))->toBe(['lines' => 1, 'shares' => 1])
        ->and(mrlBuild($report))->toBe(['lines' => 0, 'shares' => 0])
        ->and(MonthlyReportLine::query()->count())->toBe(1)
        ->and(MonthlyReportLineShare::query()->count())->toBe(1);
});

test('a later proposal reaches drafts only through a rebuild, and a submitted report gets nothing', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    $library = LibraryIndicator::factory()->create(['frequency' => CollectionFrequency::Monthly]);
    mrlLine($template, $proposal, $library);
    mrlBuild($december = mrlReport($project, '2026-12-01'));
    mrlBuild($january = mrlReport($project, '2027-01-01'));
    Activity::withoutRecording(fn () => $december->forceFill(['status' => MonthlyReportStatus::Submitted, 'submitted_at' => now()])->save());

    mrlLine($template, mrlProposal($project), $library);

    expect($january->lines()->sole()->shares()->count())->toBe(1)
        ->and(mrlBuild($january))->toBe(['lines' => 0, 'shares' => 1])
        ->and($january->lines()->sole()->shares()->count())->toBe(2)
        ->and(mrlBuild($december))->toBe(['lines' => 0, 'shares' => 0])
        ->and($december->lines()->sole()->shares()->count())->toBe(1)
        ->and(mrlBuild(mrlReport($project, '2027-02-01', MonthlyReportStatus::Validated)))->toBe(['lines' => 0, 'shares' => 0]);
});

test('building runs in a fixed number of queries, whatever the number of lines', function () {
    $count = function (int $lines): int {
        ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();

        for ($i = 0; $i < $lines; $i++) {
            mrlLine($template, $proposal);
        }

        $report = mrlReport($project, '2026-12-01');
        DB::flushQueryLog();
        DB::enableQueryLog();
        mrlBuild($report);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(1))->toBe($count(4));
});

test('required months are the generated months with at least one line', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, attributes: ['schedule' => 'selected_months', 'schedule_months' => [11, 1]]);
    foreach (['2026-11-01', '2026-12-01', '2027-01-01'] as $month) {
        mrlBuild(mrlReport($project, $month));
    }

    $periods = MonthlyReportPeriods::for($project);
    $format = fn (array $months): array => array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), $months);

    expect($format($periods->months()))->toBe(['2026-11', '2026-12', '2027-01'])
        ->and($format($periods->requiredMonths()))->toBe(['2026-11', '2027-01']);
});

test('a report cannot be submitted before the last working day of its month', function () {
    ['project' => $project] = mrlFixture();
    $november = mrlReport($project, '2026-11-01');

    $this->travelTo('2026-11-27 18:00:00');
    expect(MonthlyReportPeriods::for($project)->canSubmit($november))->toBe(__('monthly_reports.submit_too_early'));

    // Monday 30 November is the last working day of November.
    $this->travelTo('2026-11-30 08:00:00');
    expect(MonthlyReportPeriods::for($project)->canSubmit($november))->toBeNull();
});

test('the nearest earlier month with lines must be submitted first; a line-less month in between does not count', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, attributes: ['schedule' => 'selected_months', 'schedule_months' => [11, 1]]);
    foreach (['2026-11-01', '2026-12-01', '2027-01-01'] as $month) {
        mrlBuild(mrlReport($project, $month));
    }
    $november = MonthlyReport::query()->where('project_id', $project->id)->where('period', '2026-11-01')->sole();
    $january = MonthlyReport::query()->where('project_id', $project->id)->where('period', '2027-01-01')->sole();
    $this->travelTo('2027-01-29 08:00:00');

    expect(MonthlyReportPeriods::for($project)->canSubmit($january))
        ->toBe(__('monthly_reports.submit_previous_first', ['month' => CarbonImmutable::parse('2026-11-01')->translatedFormat('F Y')]));

    Activity::withoutRecording(fn () => $november->forceFill(['status' => MonthlyReportStatus::Submitted, 'submitted_at' => now()])->save());

    expect(MonthlyReportPeriods::for($project)->canSubmit($january))->toBeNull();
});

test('the month after a line-less month can be submitted', function () {
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = mrlFixture();
    mrlLine($template, $proposal, attributes: ['schedule' => 'selected_months', 'schedule_months' => [12]]);
    mrlBuild(mrlReport($project, '2026-11-01'));
    mrlBuild($december = mrlReport($project, '2026-12-01'));
    $this->travelTo('2026-12-31 08:00:00');

    expect(MonthlyReportPeriods::for($project)->canSubmit($december))->toBeNull();
});

test('a month has started from its first day', function () {
    ['project' => $project] = mrlFixture();
    $periods = MonthlyReportPeriods::for($project);

    expect($periods->hasStarted(CarbonImmutable::parse('2026-09-01')))->toBeTrue()
        ->and($periods->hasStarted(CarbonImmutable::parse('2026-10-01')))->toBeTrue()
        ->and($periods->hasStarted(CarbonImmutable::parse('2026-11-01')))->toBeFalse();
});

test('a month is due five working days after it ends, on the project\'s own working days', function () {
    ['project' => $project] = mrlFixture();
    $november = CarbonImmutable::parse('2026-11-01');

    expect(MonthlyReportPeriods::for($project)->dueDate($november)->toDateString())->toBe('2026-12-07');

    // A Saturday–Sunday project counts Dec 5, 6, 12, 13 and 19.
    Activity::withoutRecording(fn () => $project->forceFill(['working_days' => [6, 7]])->save());

    expect(MonthlyReportPeriods::for($project->fresh())->dueDate($november)->toDateString())->toBe('2026-12-19');
});

test('an owner whose HR position is at another center is flagged, never refused', function () {
    ['project' => $project] = mrlFixture();
    $cityId = insertHrCity();
    $ownCenter = insertHrCenter($cityId);
    $otherCenter = insertHrCenter($cityId, 'Other');
    insertHrContract($project->user_id, insertHrPosition(insertHrDepartment(), centerId: $ownCenter));

    Activity::withoutRecording(fn () => $project->forceFill(['center_id' => $otherCenter])->save());
    expect(MonthlyReportPeriods::ownerCenterMismatch($project))->toBeTrue();

    Activity::withoutRecording(fn () => $project->forceFill(['center_id' => $ownCenter])->save());
    expect(MonthlyReportPeriods::ownerCenterMismatch($project))->toBeFalse();

    Activity::withoutRecording(fn () => $project->forceFill(['center_id' => null])->save());
    expect(MonthlyReportPeriods::ownerCenterMismatch($project))->toBeFalse();
});
