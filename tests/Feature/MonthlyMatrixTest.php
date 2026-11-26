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
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\Result;
use App\Models\StrategicPillar;
use App\Models\User;
use App\Support\Reporting\ReportExtraction;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| The Monthly Matrix (T18 slice 1, Y-05, contracts §13, §14, §14.1): one row
| per place and library indicator (center view) or per target (donor view),
| one column per month of the From / To window, then target, total and
| achieved %. Read only, through ReportExtraction.
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

        $row = ProposalLocation::query()->where('proposal_id', $proposal->id)->where('project_id', $project->id)->first()
            ?? ProposalLocation::factory()->forProject($project)->create(['proposal_id' => $proposal->id]);

        if ($project->center_id !== null && ! $row->centers()->exists()) {
            $row->centers()->create(['center_id' => $project->center_id]);
        }

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

/**
 * The key of a legacy project's place: its report template follows the
 * project's city, program and center (Y-02 backfill).
 */
function mmPlaceKey(Project $project): string
{
    return ReportExtraction::placeKey((int) $project->city_id, (int) $project->program_id, $project->center_id === null ? null : (int) $project->center_id, false);
}

/**
 * The exported table without the filter block above its heading row.
 *
 * @return list<list<string|null>>
 */
function mmCsvTable(Testable $page): array
{
    $rows = downloadedCsv($page);
    $blank = collect($rows)->search(fn (array $row): bool => array_filter($row, fn (mixed $cell): bool => $cell !== null && $cell !== '') === []);

    return $blank === false ? $rows : array_values(array_slice($rows, $blank + 1));
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

    $rows = mmRows(Livewire::actingAs(mmViewer())->withQueryParams(['to' => '2026-12'])->test('pages::reports.monthly-matrix'));
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

test('a row is a place: center · city, or Center not set, never a project', function () {
    $cityId = insertHrCity('Aleppo', 'حلب');
    $centerId = insertHrCenter($cityId, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $withCenter = mmProject(attributes: ['center_id' => $centerId, 'city_id' => $cityId, 'name' => 'Named Project']);
    $without = mmProject(attributes: ['name' => 'No Center Project', 'city_id' => insertHrCity('Homs', 'حمص')]);
    $library = LibraryIndicator::factory()->create();

    foreach ([$withCenter, $without] as $project) {
        mmTarget(mmProposal($project), $project, $library);
    }

    $rows = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->instance()->matrix['rows'];
    $byPlace = collect($rows)->keyBy('place_key');
    $named = $byPlace[mmPlaceKey($withCenter)];

    expect($named['center'])->toBe(__('reports.matrix.place_center', ['center' => 'Hope Center Aleppo', 'city' => 'Aleppo']))
        ->and($named['city'])->toBe('Aleppo')
        ->and($named['key'])->toBe('row-'.mmPlaceKey($withCenter).'-'.$library->id)
        ->and($named['shared_centers'])->toBeNull()
        ->and($named)->not->toHaveKeys(['project_id', 'project_name', 'project_slug', 'project_start', 'project_end'])
        ->and($byPlace[mmPlaceKey($without)]['center'])->toBe(__('reports.matrix.place_not_set'))
        ->and($byPlace[mmPlaceKey($without)]['city'])->toBe('Homs');
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
    $byPlace = collect($page->instance()->matrix['rows'])->keyBy('place_key');

    expect($byPlace[mmPlaceKey($splitProject)]['total'])->toBe(10.0)
        ->and($byPlace[mmPlaceKey($splitProject)]['target'])->toBe(10.0)
        ->and($byPlace[mmPlaceKey($sharedProject)]['total'])->toBe(30.0)
        ->and($byPlace[mmPlaceKey($sharedProject)]['target'])->toBe(30.0);

    $donorRows = collect($page->set('donor', (string) $firstDonor->id)->instance()->matrix['rows'])->keyBy('place_key');

    expect($donorRows)->toHaveCount(2)
        ->and($donorRows[mmPlaceKey($splitProject)]['total'])->toBe(6.0)
        ->and($donorRows[mmPlaceKey($splitProject)]['target'])->toBe(6.0)
        ->and($donorRows[mmPlaceKey($sharedProject)]['total'])->toBe(30.0);
});

test('Whole grant runs a proposal starting in November from November to October', function () {
    $project = mmProject();
    $proposal = mmProposal($project, '2026-11-01', '2027-10-31');
    mmTarget($proposal, $project, LibraryIndicator::factory()->create());

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->set('proposal', (string) $proposal->id);

    expect($page->instance()->hasWholeGrant)->toBeTrue();

    $page->call('wholeGrant')->assertSet('from', '2026-11')->assertSet('to', '2027-10');
    $months = collect($page->instance()->matrix['months'])->map->format('Y-m');

    expect($months->first())->toBe('2026-11')->and($months->last())->toBe('2027-10')->and($months)->toHaveCount(12);

    $page->assertSeeInOrder(['Nov 2026', 'Oct 2027']);
});

test('each filter narrows the rows and an unknown id is ignored', function () {
    $cityId = insertHrCity('Idlib', 'إدلب');
    $centerId = insertHrCenter($cityId, 'Idlib Center');
    $pillar = StrategicPillar::factory()->create();
    $pillarProgram = Program::factory()->create(['strategic_pillar_id' => $pillar->id]);
    $first = mmProject(attributes: ['program_id' => $pillarProgram->id, 'city_id' => $cityId, 'center_id' => $centerId]);
    $second = mmProject();
    $firstLibrary = LibraryIndicator::factory()->create();
    $secondLibrary = LibraryIndicator::factory()->create();
    $firstProposal = mmProposal($first);
    $secondProposal = mmProposal($second);
    mmTarget($firstProposal, $first, $firstLibrary);
    mmTarget($secondProposal, $second, $secondLibrary);
    mmTarget(mmProposal($second, '2025-01-01', '2025-12-31', name: 'Old'), $second, LibraryIndicator::factory()->create());

    $employeeId = insertHrEmployee();
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forPlace(new ReportPlace((int) $second->city_id, (int) $second->program_id, $second->center_id === null ? null : (int) $second->center_id, false))->create(['employee_id' => $employeeId]));
    $viewer = userWithRole('MEAL', $employeeId);

    $codes = fn (array $filters): array => collect(Livewire::actingAs($viewer)->withQueryParams($filters)->test('pages::reports.monthly-matrix')->instance()->matrix['rows'])->pluck('code')->sort()->values()->all();

    expect($codes([]))->toBe(collect([$firstLibrary->code, $secondLibrary->code])->sort()->values()->all())
        ->and($codes(['proposal' => (string) $firstProposal->id]))->toBe([$firstLibrary->code])
        ->and($codes(['donor' => (string) $secondProposal->donor_id]))->toBe([$secondLibrary->code])
        ->and($codes(['center' => 'c'.$centerId]))->toBe([$firstLibrary->code])
        ->and($codes(['center' => 'unset']))->toBe([$secondLibrary->code])
        ->and($codes(['city' => (string) $cityId]))->toBe([$firstLibrary->code])
        ->and($codes(['program' => (string) $pillarProgram->id]))->toBe([$firstLibrary->code])
        ->and($codes(['indicator' => (string) $secondLibrary->id]))->toBe([$secondLibrary->code])
        ->and($codes(['pillar' => (string) $pillar->id]))->toBe([$firstLibrary->code])
        ->and($codes(['mine' => '1']))->toBe([$secondLibrary->code])
        ->and($codes(['year' => '2025']))->toHaveCount(1)
        ->and($codes(['proposal' => '999999', 'donor' => '999999', 'indicator' => 'abc', 'program' => '999999', 'center' => 'c999999']))->toBe($codes([]));
});

test('a stray legacy year falls back to this year, January to December', function () {
    Livewire::actingAs(mmViewer())
        ->withQueryParams(['year' => '1850'])
        ->test('pages::reports.monthly-matrix')
        ->assertSet('year', '')
        ->assertSet('from', '2026-01')
        ->assertSet('to', '2026-12');
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
    $csv = mmCsvTable($page);

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
function mmSchedule(Result $activity, string $month, ?array $weeks = null, ?ProposalLocation $row = null): ActivitySchedule
{
    return Activity::withoutRecording(fn (): ActivitySchedule => ActivitySchedule::factory()->create([
        'result_id' => $activity->id,
        // Y-15, Y-16 (D-93): the override belongs to the location row;
        // `project_id` is still written beside it until Y-18.
        'proposal_location_id' => $row?->id,
        'project_id' => $row?->project_id,
        'month' => $month,
        'weeks' => $weeks,
    ]));
}

/**
 * The location row of the proposal carrying the project.
 */
function mmRow(Proposal $proposal, Project $project): ProposalLocation
{
    return ProposalLocation::query()
        ->where('proposal_id', $proposal->id)
        ->where('project_id', $project->id)
        ->sole();
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
    $firstCity = insertHrCity('Hama');
    $firstCenter = insertHrCenter($firstCity, 'Hama Center');
    $projects = [mmProject(attributes: ['city_id' => $firstCity, 'center_id' => $firstCenter]), mmProject(), mmProject()];
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

    $single = $page->set('center', 'c'.$firstCenter)->instance()->matrix['rows'][0];

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

        return mmCsvTable($page);
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
    $csv = mmCsvTable($page);

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
    mmSchedule($scheduled, '2026-07-01', [4], mmRow($proposal, $project)); // the row's override replaces the proposal calendar

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

test('an activity linked to no statement gets its own group after the outputs', function () {
    $project = mmProject();
    $proposal = mmProposal($project);
    ['output' => $output] = mmActivities($proposal, $project);
    $free = Activity::withoutRecording(fn (): Result => Result::factory()->type(ResultType::Activity)->create([
        'statement' => 'Run a launch event.', 'proposal_id' => $proposal->id, 'program_id' => $project->program_id, 'parent_id' => null,
    ]));

    $board = Livewire::actingAs(mmViewer())->withQueryParams(['tab' => 'activities'])->test('pages::reports.monthly-matrix')->instance()->activityBoard;

    expect(array_column($board['outputs'], 'output_id'))->toBe([$output->id, 0])
        ->and($board['outputs'][1]['statement'])->toBe(__('results_framework.free_activities'))
        ->and($board['outputs'][1]['activities'][0]['activity_id'])->toBe($free->id);
});

test('the Activities tab honours the same filters and the window', function () {
    $first = mmProject();
    $secondCity = insertHrCity('Raqqa');
    $secondCenter = insertHrCenter($secondCity, 'Raqqa Center');
    $second = mmProject(attributes: ['city_id' => $secondCity, 'center_id' => $secondCenter]);
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
        ->and($ids(['center' => 'c'.$secondCenter, 'year' => '2027']))->toBe([$secondActivity->id])
        ->and($ids(['center' => 'c'.$secondCenter]))->toBe([])
        ->and($ids(['city' => (string) $secondCity, 'from' => '2027-01', 'to' => '2027-06']))->toBe([$secondActivity->id])
        ->and($ids(['program' => (string) $first->program_id]))->toBe([$firstActivity->id])
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
    $csv = mmCsvTable($page);

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

test('months outside the indicator source do not count as missing reports', function () {
    $project = mmProject(attributes: ['start_date' => '2026-01-01', 'end_date' => '2026-11-30']);
    $proposal = mmProposal($project, '2026-02-01', '2026-09-18');
    $library = LibraryIndicator::factory()->create();
    mmTarget($proposal, $project, $library);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');
    $cells = mmRows($page)[$library->code]['cells'];

    expect($cells['2026-01']['state'])->toBe(MatrixCellState::OutsidePeriod)
        ->and($cells['2026-02']['state'])->toBe(MatrixCellState::NotSubmitted)
        ->and($cells['2026-09']['state'])->toBe(MatrixCellState::NotSubmitted)
        ->and($cells['2026-10']['state'])->toBe(MatrixCellState::OutsidePeriod);
});

/*
| Y-05 (D-79): the page reads ReportExtraction, the window is From / To, and
| the old ?year= and ?center=<project id> links still open.
*/

/**
 * Three places (two centers of one city and program, one elsewhere) with a
 * count and a percentage indicator reported in March and July 2026.
 *
 * @return array{city: int, centers: list<int>, projects: list<Project>}
 */
function mmPlaces(): array
{
    $city = insertHrCity('Latakia');
    $centers = [insertHrCenter($city, 'Port Center'), insertHrCenter($city, 'Hill Center')];
    $program = Activity::withoutRecording(fn (): Program => Program::factory()->create(['donor_attribution' => DonorAttribution::Split]));
    $projects = [
        mmProject(attributes: ['city_id' => $city, 'program_id' => $program->id, 'center_id' => $centers[0]]),
        mmProject(attributes: ['city_id' => $city, 'program_id' => $program->id, 'center_id' => $centers[1]]),
        mmProject(),
    ];
    $count = LibraryIndicator::factory()->create();
    $percentage = LibraryIndicator::factory()->percentage()->create();

    foreach ($projects as $index => $project) {
        $proposal = mmProposal($project);
        $countTarget = mmTarget($proposal, $project, $count, (string) (100 + $index));
        $ratioTarget = mmTarget($proposal, $project, $percentage, '60');

        foreach (['2026-03-01' => MonthlyReportStatus::Validated, '2026-07-01' => MonthlyReportStatus::Validated, '2026-08-01' => MonthlyReportStatus::Submitted] as $month => $status) {
            $report = mmReport($project, $month, $status);
            mmLine($report, $count, [$countTarget], 10 + $index);
            mmLine($report, $percentage, [$ratioTarget], null, ['numerator' => 3 + $index, 'denominator' => 10, 'fixed_denominator' => 10]);
        }
    }

    return ['city' => $city, 'centers' => $centers, 'projects' => $projects];
}

test('the page shows the same targets and totals as ReportExtraction', function (callable $filters) {
    $places = mmPlaces();
    $page = Livewire::actingAs(mmViewer())->withQueryParams($filters($places))->test('pages::reports.monthly-matrix');
    $instance = $page->instance();

    $screen = collect($instance->matrix['rows'])->mapWithKeys(fn (array $row): array => [$row['place_key'].'|'.$row['library_id'] => [$row['target'], $row['total']]])->all();
    $extraction = collect(ReportExtraction::for($instance->extractionFilter)->centerTotals())->map(fn (array $row): array => [$row['target'], $row['total']])->all();

    expect($screen)->not->toBe([])->and($screen)->toEqual($extraction);
})->with([
    'no filter' => [fn (array $places): array => []],
    'city' => [fn (array $places): array => ['city' => (string) $places['city']]],
    'center and a range' => [fn (array $places): array => ['center' => 'c'.$places['centers'][0], 'from' => '2026-06', 'to' => '2026-09']],
]);

test('the legacy ?center=<project id> and ?year= links resolve to the place and the window, then clear', function () {
    $places = mmPlaces();
    $project = $places['projects'][1];

    Livewire::actingAs(mmViewer())
        ->withQueryParams(['center' => (string) $project->id, 'year' => '2025'])
        ->test('pages::reports.monthly-matrix')
        ->assertSet('center', 'c'.$places['centers'][1])
        ->assertSet('city', (string) $places['city'])
        ->assertSet('program', (string) $project->program_id)
        ->assertSet('from', '2025-01')
        ->assertSet('to', '2025-12')
        ->assertSet('year', '');

    $proposal = mmProposal($project, '2026-11-01', '2028-10-31', name: 'Two years');

    Livewire::actingAs(mmViewer())
        ->withQueryParams(['proposal' => (string) $proposal->id, 'year' => '2'])
        ->test('pages::reports.monthly-matrix')
        ->assertSet('from', '2027-11')
        ->assertSet('to', '2028-10')
        ->assertSet('year', '');
});

test('picking a center selects its city, and a city elsewhere clears it', function () {
    $places = mmPlaces();
    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')->set('center', 'c'.$places['centers'][0]);

    $page->assertSet('city', (string) $places['city']);
    expect(collect($page->instance()->centerOptions)->pluck('value'))->toContain('c'.$places['centers'][0], 'c'.$places['centers'][1])->not->toContain('unset');

    $page->set('city', (string) insertHrCity('Elsewhere'))->assertSet('center', '');
});

test('From after To is refused with a field error and builds no matrix', function () {
    mmPlaces();

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')
        ->set('from', '2026-09')
        ->set('to', '2026-03')
        ->assertHasErrors(['from'])
        ->assertSee(__('reports.matrix.from_after_to'));

    expect($page->instance()->matrix['rows'])->toBe([])
        ->and($page->instance()->months)->toBe([])
        ->and($page->instance()->emptyReason)->toBe('window');

    $page->call('thisYear')->assertHasNoErrors()->assertSet('from', '2026-01')->assertSet('to', '2026-12');
});

test('an empty result names the filter that emptied it', function () {
    mmPlaces();
    $program = Activity::withoutRecording(fn (): Program => Program::factory()->create());

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');

    expect($page->instance()->emptyReason)->toBeNull();

    $page->set('program', (string) $program->id);

    expect($page->instance()->matrix['rows'])->toBe([])->and($page->instance()->emptyReason)->toBe('program');
});

test('the query count is flat with three places, twelve months and five indicators', function () {
    $count = function (int $places): int {
        $libraries = [
            ...LibraryIndicator::factory()->count(3)->create()->all(),
            ...LibraryIndicator::factory()->percentage()->count(2)->create()->all(),
        ];

        foreach (range(1, $places) as $place) {
            $project = mmProject(attributes: ['center_id' => insertHrCenter(insertHrCity('City '.$place.'-'.uniqid()), 'Center '.$place)]);
            $proposal = mmProposal($project);
            $targets = array_map(fn (LibraryIndicator $library): PlanIndicator => mmTarget($proposal, $project, $library), $libraries);

            foreach (range(1, 12) as $month) {
                $report = mmReport($project, sprintf('2026-%02d-01', $month));

                foreach ($libraries as $index => $library) {
                    $isRatio = $index >= 3;
                    mmLine($report, $library, [$targets[$index]], $isRatio ? null : $month, $isRatio ? ['numerator' => $month, 'denominator' => 20, 'fixed_denominator' => 20] : []);
                }
            }
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::actingAs(mmViewer())->withQueryParams(['from' => '2026-01', 'to' => '2026-12'])->test('pages::reports.monthly-matrix');

        return $queries;
    };

    $count(1); // warm the per-process caches (permissions, HR lookups)
    $fourPlaces = $count(3);
    $sevenPlaces = $count(3); // the data adds up: 3 more places, 180 more lines

    expect($sevenPlaces)->toBe($fourPlaces);
});

test('the default window runs January to December, the last month still to come', function () {
    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix')
        ->assertSet('from', '2026-01')
        ->assertSet('to', '2026-12');

    expect($page->instance()->months)->toHaveCount(12);
});

test('a count row is behind pace against its target spread over the grant, and a missed month is told from this month', function () {
    $project = mmProject();
    $library = LibraryIndicator::factory()->create();
    $target = mmTarget(mmProposal($project), $project, $library, '120');

    mmLine(mmReport($project, '2026-01-01'), $library, [$target], 10);
    mmLine(mmReport($project, '2026-02-01'), $library, [$target], 5);
    mmLine(mmReport($project, '2026-03-01', MonthlyReportStatus::Draft), $library, [$target], 4);
    mmLine(mmReport($project, '2026-10-01', MonthlyReportStatus::Submitted), $library, [$target], 9);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');
    $row = mmRows($page)[$library->code];
    $progress = $page->instance()->rowProgress[$row['key']];

    expect($progress['expected'])->toBe(20.0)
        ->and($progress['behind'])->toBeTrue()
        ->and($progress['gap'])->toBe(5.0)
        ->and($progress['waiting'])->toBe(1)
        ->and($progress['missing'])->toBe(7) // March's draft and April to September with no report
        ->and($progress['kinds']['2026-11'])->toBe('open')
        ->and($progress['kinds']['2026-12'])->toBe('upcoming');

    $page->assertSee(__('reports.matrix.behind_by', ['gap' => '5']))
        ->assertSeeHtml('data-matrix-cell="not_submitted"');
});

test('a summary card keeps only its rows, and pressing it again shows them all', function () {
    $project = mmProject();
    $proposal = mmProposal($project, '2026-01-01', '2026-02-28');
    $onPace = LibraryIndicator::factory()->create();
    $behind = LibraryIndicator::factory()->create();
    $onPaceTarget = mmTarget($proposal, $project, $onPace, '20');
    $behindTarget = mmTarget($proposal, $project, $behind, '20');

    foreach (['2026-01-01', '2026-02-01'] as $month) {
        $report = mmReport($project, $month);
        mmLine($report, $onPace, [$onPaceTarget], 10);
        mmLine($report, $behind, [$behindTarget], 2);
    }

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');
    $codes = fn (): array => collect($page->instance()->pagedRows->items())->pluck('code')->sort()->values()->all();

    expect($page->instance()->matrixSummary)->toMatchArray(['total' => 2, 'pace' => 1, 'behind' => 1, 'missing' => 0]);

    $page->call('setFocus', 'behind')->assertSet('focus', 'behind');
    expect($codes())->toBe([$behind->code]);

    $page->call('setFocus', 'behind')->assertSet('focus', '');
    expect($codes())->toHaveCount(2);
});

test('rows group by proposal, by center or by indicator, and the search finds a code', function () {
    $project = mmProject();
    $proposal = mmProposal($project, name: 'Family to Family');
    $first = LibraryIndicator::factory()->create();
    $second = LibraryIndicator::factory()->create();
    mmTarget($proposal, $project, $first);
    mmTarget($proposal, $project, $second);

    $page = Livewire::actingAs(mmViewer())->test('pages::reports.monthly-matrix');
    $cards = fn (): array => $page->instance()->matrixCards;

    expect($cards())->toHaveCount(1)
        ->and($cards()[0]['title'])->toBe('Family to Family')
        ->and($cards()[0]['rows'])->toHaveCount(2);

    $page->call('groupBy', 'center')->assertSet('view', 'rows')->assertSet('group', 'center');
    expect($cards()[0]['title'])->toBe(__('reports.matrix.place_not_set'));

    $page->call('groupBy', 'indicator')->assertSet('view', 'grouped');
    expect($page->instance()->resolvedGroup)->toBe('indicator');

    $page->call('groupBy', 'proposal')->set('search', $second->code);
    expect(collect($page->instance()->pagedRows->items())->pluck('code')->all())->toBe([$second->code]);
});
