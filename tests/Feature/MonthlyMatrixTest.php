<?php

use App\Enums\ActivityStatus;
use App\Enums\DonorAttribution;
use App\Enums\MatrixCellState;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\ActivitySchedule;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineBreakdown;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\StrategicPillar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| The Monthly Matrix (T18 slice 1, contracts §13, §14, §14.1): one row per
| center and library indicator (center view) or per target (donor view),
| twelve month columns, then target, total and achieved %. Read only.
*/

beforeEach(fn () => $this->travelTo('2026-11-13 10:00:00'));

/**
 * An active project in a program of the given attribution mode.
 *
 * @param  array<string, mixed>  $attributes
 */
function mmProject(DonorAttribution $attribution = DonorAttribution::Shared, array $attributes = []): Project
{
    return Activity::withoutRecording(function () use ($attribution, $attributes): Project {
        $program = Program::factory()->create(['donor_attribution' => $attribution]);

        return Project::factory()->active()->create([
            'program_id' => $program->id,
            'start_date' => '2026-01-01',
            'end_date' => '2027-12-31',
            ...$attributes,
        ]);
    });
}

/**
 * A donor-approved proposal funding the project over the given dates.
 */
function mmProposal(Project $project, string $begin = '2026-01-01', string $end = '2026-12-31', ?Donor $donor = null, string $name = 'Study Zone'): Proposal
{
    return Activity::withoutRecording(function () use ($project, $begin, $end, $donor, $name): Proposal {
        $proposal = Proposal::factory()->create([
            'name' => $name,
            'donor_id' => ($donor ?? Donor::factory()->create())->id,
            'status' => ProposalStatus::Active,
            'actual_date_begin' => $begin,
            'actual_date_end' => $end,
        ]);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

        return $proposal;
    });
}

/**
 * A target of the proposal on a library entry, at the project's center.
 */
function mmTarget(Proposal $proposal, Project $project, LibraryIndicator $library, string $target = '100'): PlanIndicator
{
    return Activity::withoutRecording(function () use ($proposal, $project, $library, $target): PlanIndicator {
        $indicator = Indicator::factory()->create(['library_indicator_id' => $library->id, 'measure' => $library->measure]);

        return PlanIndicator::factory()->forProposal($proposal)->create([
            'indicator_id' => $indicator->id,
            'project_id' => $project->id,
            'target' => $target,
        ]);
    });
}

function mmReport(Project $project, string $month, MonthlyReportStatus $status = MonthlyReportStatus::Validated): MonthlyReport
{
    return Activity::withoutRecording(function () use ($project, $month, $status): MonthlyReport {
        $factory = MonthlyReport::factory();
        $factory = match ($status) {
            MonthlyReportStatus::Draft => $factory,
            MonthlyReportStatus::Submitted => $factory->submitted(),
            MonthlyReportStatus::Validated => $factory->validated(),
        };

        return $factory->create(['project_id' => $project->id, 'period' => CarbonImmutable::parse($month)->startOfMonth()->toDateString()]);
    });
}

/**
 * A line of the report with one share (and its mirror) per target.
 *
 * @param  list<PlanIndicator>  $targets
 * @param  array<string, mixed>  $extra  numerator, denominator, fixed_denominator, not_measured
 * @param  list<float|null>|null  $shareValues  one per target; defaults to the line value
 */
function mmLine(MonthlyReport $report, LibraryIndicator $library, array $targets, ?float $value, array $extra = [], ?array $shareValues = null): MonthlyReportLine
{
    return Activity::withoutRecording(function () use ($report, $library, $targets, $value, $extra, $shareValues): MonthlyReportLine {
        $line = MonthlyReportLine::factory()->create([
            'monthly_report_id' => $report->id,
            'library_indicator_id' => $library->id,
            'value' => $value,
            ...$extra,
        ]);
        $month = CarbonImmutable::parse($report->period);

        foreach (array_values($targets) as $index => $target) {
            $shareValue = $shareValues === null ? $value : $shareValues[$index];
            $share = MonthlyReportLineShare::factory()->create([
                'monthly_report_line_id' => $line->id,
                'plan_indicator_id' => $target->id,
                'value' => $shareValue,
                ...$extra,
            ]);

            Report::factory()->create([
                'plan_indicator_id' => $target->id,
                'status' => $report->status->mirrorStatus(),
                'planned_date' => $month->endOfMonth()->toDateString(),
                'period_start' => $month->toDateString(),
                'period_end' => $month->endOfMonth()->toDateString(),
                'monthly_report_id' => $report->id,
                'monthly_report_line_share_id' => $share->id,
                'value' => $shareValue,
                'numerator' => $extra['numerator'] ?? null,
                'denominator' => $extra['denominator'] ?? null,
            ]);
        }

        return $line;
    });
}

function mmViewer(string $role = 'MEAL'): User
{
    return userWithRole($role);
}

/**
 * The matrix rows of a page, keyed by library code (center view) .
 *
 * @return array<string, array<string, mixed>>
 */
function mmRows(Testable $page): array
{
    return collect($page->instance()->matrix['rows'])->keyBy('code')->all();
}

test('guests are redirected and roleless users are refused', function () {
    $this->get(route('reports.monthly-matrix'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('reports.monthly-matrix'))->assertForbidden();
});

test('MEAL and Financial can open the matrix, and a report slug still opens the report page', function () {
    $this->actingAs(userWithRole('MEAL'))->get(route('reports.monthly-matrix'))->assertOk();
    $this->actingAs(userWithRole('Financial'))->get('/reports/monthly-matrix')->assertOk()->assertSee(__('titles.monthly_matrix'));

    $report = Report::factory()->create();
    $this->actingAs(userWithRole('MEAL'))->get(route('reports.show', $report))->assertOk();
});

test('a month lands in its column and every state has its own cell', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    $library = LibraryIndicator::factory()->create();
    $target = mmTarget($proposal, $project, $library, '100');
    $other = LibraryIndicator::factory()->create();
    $otherTarget = mmTarget($proposal, $project, $other, '50');

    mmLine(mmReport($project, '2026-01-01'), $library, [$target], 12);
    mmLine(mmReport($project, '2026-02-01', MonthlyReportStatus::Submitted), $library, [$target], 7);
    mmLine(mmReport($project, '2026-03-01', MonthlyReportStatus::Draft), $library, [$target], 5);
    mmLine(mmReport($project, '2026-04-01'), $other, [$otherTarget], 1);

    $rows = mmRows(Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix'));
    $cells = $rows[$library->code]['cells'];

    expect($cells['2026-01']['state'])->toBe(MatrixCellState::Approved)
        ->and($cells['2026-01']['value'])->toBe(12.0)
        ->and($cells['2026-02']['state'])->toBe(MatrixCellState::Pending)
        ->and($cells['2026-03']['state'])->toBe(MatrixCellState::NotSubmitted)
        ->and($cells['2026-04']['state'])->toBe(MatrixCellState::NotDue)
        ->and($cells['2026-09']['state'])->toBe(MatrixCellState::NotSubmitted) // started, no report
        ->and($cells['2026-12']['state'])->toBe(MatrixCellState::Upcoming)
        ->and($rows[$library->code]['total'])->toBe(12.0); // only the validated month counts
});

test('the center column shows the HR center and city, and falls back to the project name', function () {
    $cityId = insertHrCity('Aleppo', 'حلب');
    $centerId = insertHrCenter($cityId, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $withCenter = mmProject(attributes: ['center_id' => $centerId, 'city_id' => $cityId, 'name' => 'Named Project']);
    $without = mmProject(attributes: ['name' => 'No Center Project']);
    $library = LibraryIndicator::factory()->create();

    foreach ([$withCenter, $without] as $project) {
        mmTarget(mmProposal($project), $project, $library);
    }

    $rows = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->instance()->matrix['rows'];
    $byProject = collect($rows)->keyBy('project_id');

    expect($byProject[$withCenter->id]['center'])->toBe('Hope Center Aleppo')
        ->and($byProject[$withCenter->id]['city'])->toBe('Aleppo')
        ->and($byProject[$without->id]['center'])->toBe('No Center Project')
        ->and($byProject[$without->id]['city'])->toBeNull();
});

test('counts total the validated months and a cumulative indicator takes the latest one', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    $counts = LibraryIndicator::factory()->create();
    $cumulative = LibraryIndicator::factory()->create(['is_cumulative' => true]);
    $countTarget = mmTarget($proposal, $project, $counts, '100');
    $cumulativeTarget = mmTarget($proposal, $project, $cumulative, '100');

    foreach (['2026-01-01' => [10, 10], '2026-02-01' => [20, 25]] as $month => [$count, $running]) {
        $report = mmReport($project, $month);
        mmLine($report, $counts, [$countTarget], $count);
        mmLine($report, $cumulative, [$cumulativeTarget], $running);
    }

    $rows = mmRows(Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix'));

    expect($rows[$counts->code]['total'])->toBe(30.0)
        ->and($rows[$counts->code]['achieved'])->toBe(30.0)
        ->and($rows[$cumulative->code]['total'])->toBe(25.0);
});

test('a percentage row shows the pooled level and a target of zero gives no achieved percent', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    $percentage = LibraryIndicator::factory()->percentage()->create();
    $target = mmTarget($proposal, $project, $percentage, '80');
    $zero = LibraryIndicator::factory()->create();
    $zeroTarget = mmTarget($proposal, $project, $zero, '0');

    foreach (['2026-01-01' => 20, '2026-02-01' => 30] as $month => $count) {
        $report = mmReport($project, $month);
        mmLine($report, $percentage, [$target], $count, ['numerator' => $count, 'denominator' => 100, 'fixed_denominator' => 100]);
        mmLine($report, $zero, [$zeroTarget], 4);
    }

    $rows = mmRows(Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix'));
    $pooled = PlanIndicator::pooledRatio(
        PlanIndicator::query()->with(['reports', 'indicator.libraryIndicator'])->whereKey($target->id)->get(),
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($rows[$percentage->code]['total'])->toBe(round($pooled['ratio'], 2))
        ->and($rows[$percentage->code]['total'])->toBe(50.0) // (20 + 30) ÷ 100, not an average of percentages
        ->and($rows[$percentage->code]['achieved'])->toBe(62.5)
        ->and($rows[$percentage->code]['cells']['2026-01']['numerator'])->toBe(20.0)
        ->and($rows[$zero->code]['achieved'])->toBeNull();
});

test('ratio lines without a denominator are excluded and counted', function () {
    $project = mmProject(DonorAttribution::Split);
    $proposal = mmProposal($project);
    $average = LibraryIndicator::factory()->average()->create();
    $measured = mmTarget($proposal, $project, $average, '10');
    $unmeasured = mmTarget(mmProposal($project, name: 'Second'), $project, $average, '10');

    $report = mmReport($project, '2026-01-01');
    mmLine($report, $average, [$measured], 6, ['numerator' => 12, 'denominator' => 2]);
    mmLine(mmReport($project, '2026-02-01'), $average, [$unmeasured], null, ['not_measured' => true]);

    $rows = mmRows(Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix'));

    expect($rows[$average->code]['total'])->toBe(6.0)
        ->and($rows[$average->code]['excluded'])->toBe(1);
});

test('split sums the donors on a center and a donor filter shows only that donor, shared counts once', function () {
    $splitProject = mmProject(DonorAttribution::Split);
    $sharedProject = mmProject(DonorAttribution::Shared);
    $firstDonor = Donor::factory()->create(['name' => 'First Donor']);
    $secondDonor = Donor::factory()->create(['name' => 'Second Donor']);
    $library = LibraryIndicator::factory()->create();

    $splitFirst = mmTarget(mmProposal($splitProject, donor: $firstDonor, name: 'Split A'), $splitProject, $library, '6');
    $splitSecond = mmTarget(mmProposal($splitProject, donor: $secondDonor, name: 'Split B'), $splitProject, $library, '4');
    $sharedFirst = mmTarget(mmProposal($sharedProject, donor: $firstDonor, name: 'Shared A'), $sharedProject, $library, '30');
    $sharedSecond = mmTarget(mmProposal($sharedProject, donor: $secondDonor, name: 'Shared B'), $sharedProject, $library, '30');

    mmLine(mmReport($splitProject, '2026-01-01'), $library, [$splitFirst, $splitSecond], 10, shareValues: [6, 4]);
    mmLine(mmReport($sharedProject, '2026-01-01'), $library, [$sharedFirst, $sharedSecond], 30);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');
    $byProject = collect($page->instance()->matrix['rows'])->keyBy('project_id');

    expect($byProject[$splitProject->id]['total'])->toBe(10.0)
        ->and($byProject[$splitProject->id]['target'])->toBe(10.0)
        ->and($byProject[$sharedProject->id]['total'])->toBe(30.0)
        ->and($byProject[$sharedProject->id]['target'])->toBe(30.0);

    $donorRows = collect($page->set('donor', (string) $firstDonor->id)->instance()->matrix['rows'])->keyBy('project_id');

    expect($donorRows)->toHaveCount(2)
        ->and($donorRows[$splitProject->id]['total'])->toBe(6.0)
        ->and($donorRows[$splitProject->id]['target'])->toBe(6.0)
        ->and($donorRows[$sharedProject->id]['total'])->toBe(30.0);
});

test('a proposal starting in November runs November to October', function () {
    $project = mmProject();
    $proposal = mmProposal($project, '2026-11-01', '2027-10-31');
    mmTarget($proposal, $project, LibraryIndicator::factory()->create());

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->set('proposal', (string) $proposal->id);
    $months = collect($page->instance()->matrix['months'])->map->format('Y-m');

    expect($months->first())->toBe('2026-11')->and($months->last())->toBe('2027-10')->and($months)->toHaveCount(12);

    $page->assertSeeInOrder(['Nov 2026', 'Oct 2027']);
});

test('each filter narrows the rows and an unknown id is ignored', function () {
    $cityId = insertHrCity('Idlib', 'إدلب');
    $pillar = StrategicPillar::factory()->create();
    $pillarProgram = Program::factory()->create(['strategic_pillar_id' => $pillar->id]);
    $first = mmProject(attributes: ['program_id' => $pillarProgram->id, 'city_id' => $cityId]);
    $second = mmProject();
    $firstLibrary = LibraryIndicator::factory()->create();
    $secondLibrary = LibraryIndicator::factory()->create();
    $firstProposal = mmProposal($first);
    $secondProposal = mmProposal($second);
    mmTarget($firstProposal, $first, $firstLibrary);
    mmTarget($secondProposal, $second, $secondLibrary);
    mmTarget(mmProposal($second, '2025-01-01', '2025-12-31', name: 'Old'), $second, LibraryIndicator::factory()->create());

    $employeeId = insertHrEmployee();
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($second->id)->create(['employee_id' => $employeeId]));
    $viewer = userWithRole('MEAL', $employeeId);

    $codes = fn (array $filters): array => collect(Livewire::actingAs($viewer)->withQueryParams($filters)->test('pages::reports.monthly-matrix')->instance()->matrix['rows'])->pluck('code')->sort()->values()->all();

    expect($codes([]))->toBe(collect([$firstLibrary->code, $secondLibrary->code])->sort()->values()->all())
        ->and($codes(['proposal' => (string) $firstProposal->id]))->toBe([$firstLibrary->code])
        ->and($codes(['donor' => (string) $secondProposal->donor_id]))->toBe([$secondLibrary->code])
        ->and($codes(['center' => (string) $first->id]))->toBe([$firstLibrary->code])
        ->and($codes(['city' => (string) $cityId]))->toBe([$firstLibrary->code])
        ->and($codes(['indicator' => (string) $secondLibrary->id]))->toBe([$secondLibrary->code])
        ->and($codes(['pillar' => (string) $pillar->id]))->toBe([$firstLibrary->code])
        ->and($codes(['mine' => '1']))->toBe([$secondLibrary->code])
        ->and($codes(['year' => '2025']))->toHaveCount(1)
        ->and($codes(['proposal' => '999999', 'donor' => '999999', 'indicator' => 'abc']))->toBe($codes([]));
});

test('the header picker shows the resolved year', function () {
    Livewire::actingAs(mmViewer())
        ->withQueryParams(['year' => '1850'])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('<span class="truncate">2026</span>');
});

test('my projects without coverage is an empty result with a hint', function () {
    $project = mmProject();
    mmTarget(mmProposal($project), $project, LibraryIndicator::factory()->create());

    Livewire::actingAs(mmViewer())
        ->withQueryParams(['mine' => '1'])
        ->test('pages::reports.monthly-matrix')
        ->assertSee(__('reports.matrix.no_assigned_projects'));
});

test('MEAL sees the donor name without a link and no amount, Financial gets the link', function () {
    $project = mmProject();
    $donor = Donor::factory()->create(['name' => 'Gulf Donor']);
    mmTarget(mmProposal($project, donor: $donor), $project, LibraryIndicator::factory()->create());
    insertDonorProposal($donor->id, Proposal::query()->latest('id')->value('id'), '987654.00');

    $meal = Livewire::actingAs(userWithRole('MEAL'))->test('pages::reports.monthly-matrix')->assertSee('Gulf Donor');
    $mealHtml = $meal->html();

    expect($mealHtml)->not->toContain(route('donors.show', $donor))->not->toContain('987,654');

    Livewire::actingAs(userWithRole('Financial'))->test('pages::reports.monthly-matrix')->assertSeeHtml(route('donors.show', $donor));
});

test('the CSV carries the screen rows without money and a filtered-out target is absent', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    $shown = LibraryIndicator::factory()->create();
    $hidden = LibraryIndicator::factory()->create();
    $target = mmTarget($proposal, $project, $shown, '100');
    mmTarget($proposal, $project, $hidden, '100');
    mmLine(mmReport($project, '2026-01-01'), $shown, [$target], 12);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->set('indicator', (string) $shown->id)->call('exportCsv');
    $page->assertFileDownloaded('monthly-matrix-2026-11-13.csv');
    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(2)
        ->and($csv[0])->toContain(__('reports.matrix.target'))
        ->and(implode(',', $csv[1]))->toContain($shown->code)->not->toContain($hidden->code)
        ->and(strtolower(implode(',', $csv[0])))->not->toContain('budget')->not->toContain('currency')
        ->and($csv[1])->toContain('12');
});

test('the eleventh export within a minute is refused', function () {
    $user = mmViewer();
    RateLimiter::clear('monthly-matrix-export:'.$user->id);
    $page = Livewire::actingAs($user)->test('pages::reports.monthly-matrix');

    foreach (range(1, 10) as $attempt) {
        $page->call('exportCsv')->assertFileDownloaded();
    }

    $page->call('exportCsv')->assertNoFileDownloaded();
});

test('exporting needs reports.view', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::reports.monthly-matrix')->call('exportCsv')->assertForbidden();
});

test('the query count does not grow with the number of rows', function () {
    $count = function (int $rows): int {
        $project = mmProject();
        $proposal = mmProposal($project);
        $report = mmReport($project, '2026-01-01');

        foreach (range(1, $rows) as $index) {
            $isRatio = $index % 2 === 0;
            $library = $isRatio ? LibraryIndicator::factory()->percentage()->create() : LibraryIndicator::factory()->create();
            $target = mmTarget($proposal, $project, $library);
            mmLine($report, $library, [$target], $index, $isRatio ? ['numerator' => $index, 'denominator' => 100] : []);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');

        return $queries;
    };

    $count(1); // warm the per-process caches (permissions, HR lookups)
    $small = $count(3);
    $large = $count(30);

    expect($large)->toBe($small);
});

test('Arabic shows Arabic month names in chronological order with numbers kept left to right', function () {
    $project = mmProject();
    $library = LibraryIndicator::factory()->create();
    $target = mmTarget(mmProposal($project), $project, $library);
    mmLine(mmReport($project, '2026-01-01'), $library, [$target], 12);

    $user = mmViewer();
    $user->forceFill(['locale' => 'ar'])->save();

    $page = $this->actingAs($user)->get(route('reports.monthly-matrix'))->assertOk();

    $page->assertSeeInOrder(['كانون الثاني 2026', 'شباط 2026', 'آذار 2026'])->assertSee('dir="ltr"', false);
});

/*
| Slice 2 (T18): group by indicator, disaggregation rows (D-50), Activities tab (D-51).
*/

/**
 * A by-sex breakdown row of a line (line level) or of one of its shares.
 *
 * @param  array<string, float|int|string|null>  $figures  value, numerator, denominator and their disabled_ twins
 */
function mmBreakdown(MonthlyReportLine $line, string $gender, array $figures, ?MonthlyReportLineShare $share = null): MonthlyReportLineBreakdown
{
    return Activity::withoutRecording(fn (): MonthlyReportLineBreakdown => MonthlyReportLineBreakdown::factory()->create([
        'monthly_report_line_id' => $line->id,
        'monthly_report_line_share_id' => $share?->id,
        'gender' => $gender,
        'value' => null,
        'disabled_value' => null,
        ...$figures,
    ]));
}

/**
 * A proposal-framework output with activities under it.
 *
 * @return array{output: Result, activities: list<Result>}
 */
function mmActivities(Proposal $proposal, Project $project, int $count = 1, string $statement = 'Output A'): array
{
    return Activity::withoutRecording(function () use ($proposal, $project, $count, $statement): array {
        $output = Result::factory()->type(ResultType::Output)->create(['statement' => $statement, 'proposal_id' => $proposal->id, 'program_id' => $project->program_id]);
        $activities = [];

        foreach (range(1, $count) as $index) {
            $activities[] = Result::factory()->activity($output)->create(['statement' => "{$statement} activity {$index}"]);
        }

        return ['output' => $output, 'activities' => $activities];
    });
}

function mmEntry(MonthlyReport $report, Result $activity, ActivityStatus $status): MonthlyReportActivityEntry
{
    return Activity::withoutRecording(fn (): MonthlyReportActivityEntry => MonthlyReportActivityEntry::factory()->create([
        'monthly_report_id' => $report->id,
        'result_id' => $activity->id,
        'status' => $status,
    ]));
}

/**
 * @param  list<int>|null  $weeks
 */
function mmSchedule(Result $activity, string $month, ?array $weeks = null, ?Project $project = null): ActivitySchedule
{
    return Activity::withoutRecording(fn (): ActivitySchedule => ActivitySchedule::factory()->create([
        'result_id' => $activity->id,
        'project_id' => $project?->id,
        'month' => $month,
        'weeks' => $weeks,
    ]));
}

test('grouped mode sums counts across centers: target, cells and total', function () {
    $library = LibraryIndicator::factory()->create();
    $first = mmProject();
    $second = mmProject();
    $firstTarget = mmTarget(mmProposal($first), $first, $library, '60');
    $secondTarget = mmTarget(mmProposal($second), $second, $library, '40');

    mmLine(mmReport($first, '2026-01-01'), $library, [$firstTarget], 12);
    mmLine(mmReport($second, '2026-01-01'), $library, [$secondTarget], 8);
    mmLine(mmReport($first, '2026-02-01', MonthlyReportStatus::Submitted), $library, [$firstTarget], 5);

    $rows = Livewire::actingAs(mmViewer())->withQueryParams(['view' => 'grouped'])->test('pages::reports.monthly-matrix')->instance()->matrix['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['is_group'])->toBeTrue()
        ->and($rows[0]['centers_count'])->toBe(2)
        ->and($rows[0]['target'])->toBe(100.0)
        ->and($rows[0]['cells']['2026-01']['value'])->toBe(20.0)
        ->and($rows[0]['cells']['2026-01']['state'])->toBe(MatrixCellState::Approved)
        ->and($rows[0]['cells']['2026-02']['state'])->toBe(MatrixCellState::Pending)
        ->and($rows[0]['total'])->toBe(20.0) // the pending month never counts
        ->and($rows[0]['achieved'])->toBe(20.0);
});

test('a grouped percentage pools numerators over denominators and counts what it left out', function () {
    $library = LibraryIndicator::factory()->percentage()->create();
    $projects = [mmProject(), mmProject(), mmProject()];
    $targets = [];

    foreach ([[$projects[0], '80'], [$projects[1], '60'], [$projects[2], '60']] as $index => [$project, $target]) {
        $targets[$index] = mmTarget(mmProposal($project), $project, $library, $target);
    }

    mmLine(mmReport($projects[0], '2026-01-01'), $library, [$targets[0]], 30, ['numerator' => 30, 'denominator' => 100, 'fixed_denominator' => 100]);
    mmLine(mmReport($projects[1], '2026-01-01'), $library, [$targets[1]], 20, ['numerator' => 10, 'denominator' => 50, 'fixed_denominator' => 50]);
    mmLine(mmReport($projects[2], '2026-01-01'), $library, [$targets[2]], null, ['not_measured' => true]);

    $page = Livewire::actingAs(mmViewer())->withQueryParams(['view' => 'grouped'])->test('pages::reports.monthly-matrix');
    $row = $page->instance()->matrix['rows'][0];

    expect($row['total'])->toBe(26.67) // (30 + 10) ÷ (100 + 50), not (30% + 20%) ÷ 2
        ->and($row['excluded'])->toBe(1)
        ->and($row['cells']['2026-01']['value'])->toBe(26.67)
        ->and($row['target'])->toBeNull() // targets differ: never averaged
        ->and($row['achieved'])->toBeNull();

    $page->assertSee(__('reports.matrix.not_poolable', ['count' => 1]));

    $single = $page->set('center', (string) $projects[0]->id)->instance()->matrix['rows'][0];

    expect($single['target'])->toBe(80.0)->and($single['total'])->toBe(30.0);
});

test('a shared program counts one target per center in a group and a cumulative group is flagged', function () {
    $library = LibraryIndicator::factory()->create(['is_cumulative' => true]);
    $project = mmProject(DonorAttribution::Shared);
    $first = mmTarget(mmProposal($project, name: 'Shared A'), $project, $library, '30');
    $second = mmTarget(mmProposal($project, name: 'Shared B'), $project, $library, '30');
    mmLine(mmReport($project, '2026-01-01'), $library, [$first, $second], 30);

    $page = Livewire::actingAs(mmViewer())->withQueryParams(['view' => 'grouped'])->test('pages::reports.monthly-matrix');
    $row = $page->instance()->matrix['rows'][0];

    expect($row['total'])->toBe(30.0)->and($row['target'])->toBe(30.0)->and($row['is_cumulative'])->toBeTrue();

    $page->assertSee(__('reports.matrix.cumulative_group_note'));
});

test('a disaggregated count row breaks down by sex and persons with disability, validated months only', function () {
    $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
    $project = mmProject();
    $target = mmTarget(mmProposal($project), $project, $library, '100');

    $january = mmLine(mmReport($project, '2026-01-01'), $library, [$target], 10);
    mmBreakdown($january, 'male', ['value' => 6, 'disabled_value' => 1]);
    mmBreakdown($january, 'female', ['value' => 4, 'disabled_value' => 2]);
    $february = mmLine(mmReport($project, '2026-02-01'), $library, [$target], 7);
    mmBreakdown($february, 'male', ['value' => 3, 'disabled_value' => 0]);
    mmBreakdown($february, 'female', ['value' => 4, 'disabled_value' => 1]);
    $pending = mmLine(mmReport($project, '2026-03-01', MonthlyReportStatus::Submitted), $library, [$target], 9);
    mmBreakdown($pending, 'male', ['value' => 9, 'disabled_value' => 9]);

    $row = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->instance()->matrix['rows'][0];
    $breakdown = $row['breakdown'];

    expect($row['is_disaggregated'])->toBeTrue()
        ->and($breakdown['male']['cells']['2026-01'])->toBe(6.0)
        ->and($breakdown['male']['total'])->toBe(9.0)
        ->and($breakdown['male']['pwd']['total'])->toBe(1.0)
        ->and($breakdown['female']['total'])->toBe(8.0)
        ->and($breakdown['female']['pwd']['cells']['2026-02'])->toBe(1.0)
        ->and($breakdown['total']['cells']['2026-01'])->toBe(10.0)
        ->and($breakdown['total']['total'])->toBe(17.0)
        ->and($breakdown['total']['pwd']['total'])->toBe(4.0)
        ->and($breakdown['male']['cells']['2026-03'])->toBeNull(); // pending is never summed
});

test('a split row sums its share breakdowns while a donor filter reads one share', function () {
    $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
    $split = mmProject(DonorAttribution::Split);
    $firstProposal = mmProposal($split, name: 'Split A');
    $first = mmTarget($firstProposal, $split, $library, '6');
    $second = mmTarget(mmProposal($split, name: 'Split B'), $split, $library, '4');
    $line = mmLine(mmReport($split, '2026-01-01'), $library, [$first, $second], 10, shareValues: [6, 4]);
    [$shareA, $shareB] = $line->shares()->orderBy('id')->get()->all();
    mmBreakdown($line, 'male', ['value' => 4, 'disabled_value' => 1], $shareA);
    mmBreakdown($line, 'female', ['value' => 2], $shareA);
    mmBreakdown($line, 'male', ['value' => 1], $shareB);
    mmBreakdown($line, 'female', ['value' => 3], $shareB);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');
    $row = $page->instance()->matrix['rows'][0];

    expect($row['breakdown']['male']['total'])->toBe(5.0)
        ->and($row['breakdown']['female']['total'])->toBe(5.0)
        ->and($row['breakdown']['total']['total'])->toBe(10.0);

    $donorRow = $page->set('proposal', (string) $firstProposal->id)->instance()->matrix['rows'][0];

    expect($donorRow['breakdown']['male']['total'])->toBe(4.0)->and($donorRow['breakdown']['total']['total'])->toBe(6.0);
});

test('a ratio category is the sum of numerators over the sum of denominators', function () {
    $library = LibraryIndicator::factory()->average()->create(['is_disaggregated' => true]);
    $project = mmProject();
    $target = mmTarget(mmProposal($project), $project, $library, '4');

    $january = mmLine(mmReport($project, '2026-01-01'), $library, [$target], 5, ['numerator' => 10, 'denominator' => 2]);
    mmBreakdown($january, 'male', ['numerator' => 10, 'denominator' => 2]);
    $february = mmLine(mmReport($project, '2026-02-01'), $library, [$target], 3, ['numerator' => 30, 'denominator' => 10]);
    mmBreakdown($february, 'male', ['numerator' => 30, 'denominator' => 10]);

    $male = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->instance()->matrix['rows'][0]['breakdown']['male'];

    expect($male['cells']['2026-01'])->toBe(5.0)
        ->and($male['cells']['2026-02'])->toBe(3.0)
        ->and($male['total'])->toBe(3.33); // 40 ÷ 12, not the 4 an average of 5 and 3 gives
});

test('a fixed-denominator row breaks down into counts only', function () {
    $library = LibraryIndicator::factory()->percentage()->create(['is_disaggregated' => true]);
    $project = mmProject();
    $target = mmTarget(mmProposal($project), $project, $library, '80');
    $line = mmLine(mmReport($project, '2026-01-01'), $library, [$target], 20, ['numerator' => 20, 'denominator' => 100, 'fixed_denominator' => 100]);
    mmBreakdown($line, 'male', ['numerator' => 12, 'disabled_numerator' => 1]);
    mmBreakdown($line, 'female', ['numerator' => 8, 'disabled_numerator' => 0]);

    $row = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->instance()->matrix['rows'][0];

    expect($row['breakdown_kind'])->toBe('fixed')
        ->and($row['breakdown']['male']['cells']['2026-01'])->toBe(12.0)
        ->and($row['breakdown']['total']['total'])->toBe(20.0);
});

test('the pwd filter reveals the disability rows and a row without disaggregation has no breakdown', function () {
    $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
    $plain = LibraryIndicator::factory()->create();
    $project = mmProject();
    $proposal = mmProposal($project);
    $target = mmTarget($proposal, $project, $library);
    $line = mmLine(mmReport($project, '2026-01-01'), $library, [$target], 10);
    mmBreakdown($line, 'male', ['value' => 10, 'disabled_value' => 3]);
    mmTarget($proposal, $project, $plain);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');

    expect(collect($page->instance()->matrix['rows'])->firstWhere('code', $plain->code)['breakdown'])->toBeNull();

    $page->assertSee(__('reports.matrix.sex.male'))->assertDontSee(__('reports.matrix.of_whom_pwd'));
    $page->set('pwd', true)->assertSee(__('reports.matrix.of_whom_pwd'));
});

test('grouped mode adds the breakdowns of its rows', function () {
    $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
    $first = mmProject();
    $second = mmProject();

    foreach ([[$first, 6], [$second, 4]] as [$project, $male]) {
        $target = mmTarget(mmProposal($project), $project, $library);
        $line = mmLine(mmReport($project, '2026-01-01'), $library, [$target], $male + 1);
        mmBreakdown($line, 'male', ['value' => $male, 'disabled_value' => 1]);
        mmBreakdown($line, 'female', ['value' => 1]);
    }

    $row = Livewire::actingAs(mmViewer())->withQueryParams(['view' => 'grouped'])->test('pages::reports.monthly-matrix')->instance()->matrix['rows'][0];

    expect($row['breakdown']['male']['total'])->toBe(10.0)
        ->and($row['breakdown']['male']['pwd']['total'])->toBe(2.0)
        ->and($row['breakdown']['total']['total'])->toBe(12.0);
});

test('the CSV carries the breakdown rows after their indicator, the pwd ones only when asked', function () {
    $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
    $project = mmProject();
    $line = mmLine(mmReport($project, '2026-01-01'), $library, [mmTarget(mmProposal($project), $project, $library)], 10);
    mmBreakdown($line, 'male', ['value' => 6, 'disabled_value' => 1]);
    mmBreakdown($line, 'female', ['value' => 4, 'disabled_value' => 2]);

    $export = function (bool $pwd) {
        $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->set('pwd', $pwd)->call('exportCsv');

        return downloadedCsv($page);
    };

    $plain = $export(false);
    $withPwd = $export(true);

    expect($plain)->toHaveCount(5) // heading, indicator, male, female, total
        ->and($withPwd)->toHaveCount(8) // + one "of whom" row per category
        ->and(implode(',', $plain[2]))->toContain(__('reports.matrix.sex.male'))->toContain('6')
        ->and(implode(',', $withPwd[3]))->toContain(__('reports.matrix.of_whom_pwd'))
        ->and(strtolower(implode(',', $plain[0])))->not->toContain('budget')->not->toContain('currency');
});

test('the grouped CSV has one row per indicator', function () {
    $library = LibraryIndicator::factory()->create();

    foreach ([mmProject(), mmProject()] as $project) {
        mmTarget(mmProposal($project), $project, $library, '10');
    }

    $page = Livewire::actingAs(mmViewer())->withQueryParams(['view' => 'grouped'])->test('pages::reports.monthly-matrix')->call('exportCsv');
    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(2)->and(implode(',', $csv[1]))->toContain($library->code)->toContain('20');
});

test('the Activities tab shows validated statuses, carries them forward and plans the rest', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    ['output' => $output, 'activities' => [$activity, $scheduled]] = mmActivities($proposal, $project, 2);

    mmEntry(mmReport($project, '2026-01-01'), $activity, ActivityStatus::Started);
    mmEntry(mmReport($project, '2026-02-01'), $activity, ActivityStatus::Ongoing);
    mmEntry(mmReport($project, '2026-04-01', MonthlyReportStatus::Submitted), $activity, ActivityStatus::Completed); // pending: ignored
    mmSchedule($activity, '2026-05-01', [1, 2]);
    mmSchedule($scheduled, '2026-06-01');
    mmSchedule($scheduled, '2026-07-01', [4], $project); // the project's override replaces the proposal calendar

    $page = Livewire::actingAs(mmViewer())->withQueryParams(['tab' => 'activities'])->test('pages::reports.monthly-matrix');
    $board = $page->instance()->activityBoard;
    $rows = collect($board['outputs'])->flatMap(fn (array $group): array => $group['activities'])->keyBy('activity_id');

    expect($board['outputs'])->toHaveCount(1)
        ->and($board['outputs'][0]['output_id'])->toBe($output->id)
        ->and($rows[$activity->id]['cells']['2026-01']['kind'])->toBe('status')
        ->and($rows[$activity->id]['cells']['2026-01']['status'])->toBe(ActivityStatus::Started)
        ->and($rows[$activity->id]['cells']['2026-02']['status'])->toBe(ActivityStatus::Ongoing)
        ->and($rows[$activity->id]['cells']['2026-03']['kind'])->toBe('carried')
        ->and($rows[$activity->id]['cells']['2026-03']['status'])->toBe(ActivityStatus::Ongoing)
        ->and($rows[$activity->id]['cells']['2026-04']['status'])->toBe(ActivityStatus::Ongoing) // not the pending Completed
        ->and($rows[$activity->id]['cells']['2026-05'])->toMatchArray(['kind' => 'planned', 'weeks' => [1, 2]])
        ->and($rows[$scheduled->id]['cells']['2026-06']['kind'])->toBe('empty') // the override has rows, the calendar is ignored
        ->and($rows[$scheduled->id]['cells']['2026-07'])->toMatchArray(['kind' => 'planned', 'weeks' => [4]]);

    $page->assertSee(__('enums.activity_status.ongoing'))->assertSee($output->statement);
});

test('the Activities tab honours the same filters and the window', function () {
    $first = mmProject();
    $second = mmProject();
    $firstProposal = mmProposal($first);
    $secondProposal = mmProposal($second, '2027-01-01', '2027-12-31', name: 'Next year');
    ['activities' => [$firstActivity]] = mmActivities($firstProposal, $first, 1, 'First output');
    ['activities' => [$secondActivity]] = mmActivities($secondProposal, $second, 1, 'Second output');

    $ids = fn (array $filters): array => collect(Livewire::actingAs(mmViewer())->withQueryParams(['tab' => 'activities', ...$filters])->test('pages::reports.monthly-matrix')->instance()->activityBoard['outputs'])
        ->flatMap(fn (array $group): array => $group['activities'])->pluck('activity_id')->all();

    expect($ids([]))->toBe([$firstActivity->id]) // the second proposal starts after the 2026 window
        ->and($ids(['year' => '2027']))->toBe([$secondActivity->id])
        ->and($ids(['proposal' => (string) $firstProposal->id]))->toBe([$firstActivity->id])
        ->and($ids(['donor' => (string) $firstProposal->donor_id]))->toBe([$firstActivity->id])
        ->and($ids(['center' => (string) $second->id, 'year' => '2027']))->toBe([$secondActivity->id])
        ->and($ids(['center' => (string) $second->id]))->toBe([])
        ->and($ids(['mine' => '1']))->toBe([]);
});

test('the Activities export has the same permission, rate limit and no money', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    ['activities' => [$activity]] = mmActivities($proposal, $project);
    mmEntry(mmReport($project, '2026-01-01'), $activity, ActivityStatus::Completed);
    mmSchedule($activity, '2026-05-01', [2, 3]);

    $user = mmViewer();
    RateLimiter::clear('monthly-matrix-export:'.$user->id);
    $page = Livewire::actingAs($user)->withQueryParams(['tab' => 'activities'])->test('pages::reports.monthly-matrix')->call('exportCsv');
    $page->assertFileDownloaded('monthly-matrix-activities-2026-11-13.csv');
    $csv = downloadedCsv($page);

    expect($csv)->toHaveCount(2)
        ->and(implode(',', $csv[1]))->toContain($activity->statement)->toContain(__('enums.activity_status.completed'))
        ->and(strtolower(implode(',', $csv[0])))->not->toContain('budget')->not->toContain('currency');

    foreach (range(1, 9) as $attempt) {
        $page->call('exportCsv')->assertFileDownloaded();
    }

    $page->call('exportCsv')->assertNoFileDownloaded();
    Livewire::actingAs(User::factory()->create())->withQueryParams(['tab' => 'activities'])->test('pages::reports.monthly-matrix')->call('exportCsv')->assertForbidden();
});

test('the query count stays flat in grouped mode, with breakdowns, and on the Activities tab', function () {
    $count = function (int $rows, array $query): int {
        $project = mmProject();
        $proposal = mmProposal($project);
        $report = mmReport($project, '2026-01-01');
        ['activities' => $activities] = mmActivities($proposal, $project, $rows);

        foreach (range(1, $rows) as $index) {
            $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
            $line = mmLine($report, $library, [mmTarget($proposal, $project, $library)], $index);
            mmBreakdown($line, 'male', ['value' => $index, 'disabled_value' => 0]);
            mmEntry($report, $activities[$index - 1], ActivityStatus::Started);
            mmSchedule($activities[$index - 1], '2026-02-01', [1]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::actingAs(mmViewer())->withQueryParams($query)->test('pages::reports.monthly-matrix');

        return $queries;
    };

    foreach ([['view' => 'grouped', 'pwd' => '1'], ['tab' => 'activities'], ['pwd' => '1']] as $query) {
        $count(1, $query); // warm the per-process caches
        $small = $count(3, $query);
        $large = $count(30, $query);

        expect($large)->toBe($small);
    }
});

test('Arabic renders the Activities tab with Arabic status labels and the grouped view in order', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    ['activities' => [$activity]] = mmActivities($proposal, $project);
    mmEntry(mmReport($project, '2026-01-01'), $activity, ActivityStatus::Started);
    $user = mmViewer();
    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user)->get(route('reports.monthly-matrix', ['tab' => 'activities']))
        ->assertOk()
        ->assertSee(trans('enums.activity_status.started', [], 'ar'))
        ->assertSeeInOrder(['كانون الثاني 2026', 'شباط 2026', 'آذار 2026']);

    $this->actingAs($user)->get(route('reports.monthly-matrix', ['view' => 'grouped']))->assertOk()->assertSee(trans('reports.matrix.view_grouped', [], 'ar'));
});

test('an unknown view or tab falls back to the rows of the matrix', function () {
    $page = Livewire::actingAs(mmViewer())->withQueryParams(['view' => 'bogus', 'tab' => 'bogus'])->test('pages::reports.monthly-matrix');

    expect($page->instance()->resolvedView)->toBe('rows')->and($page->instance()->resolvedTab)->toBe('matrix');
});
