<?php

use App\Enums\DonorAttribution;
use App\Enums\MatrixCellState;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
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
