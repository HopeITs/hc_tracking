<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Y-05 slice 1: the Monthly Matrix export states its filters and window in a
| header block, then carries the screen's rows and totals, in the page's
| language only and without money. Jul–Oct 2026:
|
| Aleppo · center one   proposal A, target 100: 10 + 20 + 5 validated, 7 submitted (Oct)
| Homs · center three   proposal B, target 40: 2 validated (Jul)
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');

    Activity::withoutRecording(function (): void {
        $aleppo = insertHrCity('Aleppo', 'حلب');
        $homs = insertHrCity('Homs', 'حمص');
        $program = Program::factory()->create(['name_en' => 'Education']);
        $centerOne = insertHrCenter($aleppo, 'Center One', 'المركز الأول');
        $centerThree = insertHrCenter($homs, 'Center Three', 'المركز الثالث');
        $donor = Donor::factory()->create();
        $library = LibraryIndicator::factory()->create(['name_en' => 'Children enrolled', 'name_ar' => 'الأطفال المسجلون']);

        $proposalA = mxeProposal($donor, 'Grant Alpha');
        $proposalB = mxeProposal($donor, 'Grant Beta');
        $aleppoPlace = new ReportPlace($aleppo, $program->id, $centerOne, false);
        $homsPlace = new ReportPlace($homs, $program->id, $centerThree, false);
        $aleppoTemplate = ReportTemplate::factory()->confirmed()->forPlace($aleppoPlace, $proposalA)->create();
        $homsTemplate = ReportTemplate::factory()->confirmed()->forPlace($homsPlace, $proposalB)->create();
        $aleppoTarget = mxeTarget($proposalA, $library, '100', $aleppoPlace);
        $homsTarget = mxeTarget($proposalB, $library, '40', $homsPlace);

        mxeLine($aleppoTemplate, '2026-07', $library, $aleppoTarget, 10);
        mxeLine($aleppoTemplate, '2026-08', $library, $aleppoTarget, 20);
        mxeLine($aleppoTemplate, '2026-09', $library, $aleppoTarget, 5);
        mxeLine($aleppoTemplate, '2026-10', $library, $aleppoTarget, 7, MonthlyReportStatus::Submitted);
        mxeLine($homsTemplate, '2026-07', $library, $homsTarget, 2);

        $this->mxe = compact('aleppo', 'homs', 'program', 'centerOne', 'centerThree', 'library', 'proposalA', 'proposalB');
    });
});

function mxeProposal(Donor $donor, string $name): Proposal
{
    return Proposal::factory()->create([
        'name' => $name,
        'donor_id' => $donor->id,
        'status' => ProposalStatus::Active,
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
    ]);
}

function mxeTarget(Proposal $proposal, LibraryIndicator $library, string $target, ReportPlace $place): PlanIndicator
{
    $indicator = Indicator::factory()->create(['library_indicator_id' => $library->id, 'measure' => $library->measure]);

    return PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => $indicator->id,
        'target' => $target,
        'city_id' => $place->cityId,
        'program_id' => $place->programId,
        'center_id' => $place->centerId,
        'city_wide' => $place->cityWide,
    ]);
}

/**
 * A line of the template's report of the month, with its share and its
 * `reports` mirror, as the monthly report writers leave them.
 */
function mxeLine(ReportTemplate $template, string $month, LibraryIndicator $library, PlanIndicator $target, float $value, MonthlyReportStatus $status = MonthlyReportStatus::Validated): void
{
    $period = CarbonImmutable::parse($month.'-01');
    $factory = match ($status) {
        MonthlyReportStatus::Draft => MonthlyReport::factory(),
        MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted(),
        MonthlyReportStatus::Validated => MonthlyReport::factory()->validated(),
    };
    $report = $factory->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => $period->toDateString()]);
    $line = MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id, 'library_indicator_id' => $library->id, 'value' => $value]);
    $share = MonthlyReportLineShare::factory()->create(['monthly_report_line_id' => $line->id, 'plan_indicator_id' => $target->id, 'value' => $value]);

    Report::factory()->create([
        'plan_indicator_id' => $target->id,
        'status' => $report->status->mirrorStatus(),
        'planned_date' => $period->endOfMonth()->toDateString(),
        'period_start' => $period->toDateString(),
        'period_end' => $period->endOfMonth()->toDateString(),
        'monthly_report_id' => $report->id,
        'monthly_report_line_share_id' => $share->id,
        'value' => $value,
    ]);
}

/**
 * The downloaded file split into its header block (without the closing empty
 * row), its heading row and its data rows.
 *
 * @return array{header: list<list<string|null>>, heading: list<string>, rows: list<list<string>>}
 */
function mxeFile(Testable $page): array
{
    $csv = downloadedCsv($page);
    $headingIndex = collect($csv)->search(fn (array $row): bool => $row[0] === __('reports.matrix.code'));

    expect($headingIndex)->not->toBeFalse()
        ->and($csv[$headingIndex - 1])->toBe([null]);

    return [
        'header' => array_slice($csv, 0, $headingIndex - 1),
        'heading' => $csv[$headingIndex],
        'rows' => array_slice($csv, $headingIndex + 1),
    ];
}

test('a filtered file carries the same totals as the screen', function () {
    $page = Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['city' => (string) $this->mxe['aleppo'], 'from' => '2026-07', 'to' => '2026-10'])
        ->test('pages::reports.monthly-matrix');

    $screen = collect($page->instance()->matrix['rows'])->map(fn (array $row): array => [$row['code'], $row['center'], $row['total']])->values()->all();
    $file = mxeFile($page->call('exportCsv'));
    $totalColumn = array_search(__('reports.matrix.total'), $file['heading'], true);
    $centerColumn = array_search(__('reports.matrix.center'), $file['heading'], true);

    expect($screen)->toEqual([[$this->mxe['library']->code, 'Center One · Aleppo', 35.0]])
        ->and(collect($file['rows'])->map(fn (array $row): array => [$row[0], $row[$centerColumn], (float) $row[$totalColumn]])->all())->toEqual($screen);
});

test('the header block names every active filter in words, then the window', function () {
    $page = Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams([
            'proposal' => (string) $this->mxe['proposalA']->id,
            'city' => (string) $this->mxe['aleppo'],
            'program' => (string) $this->mxe['program']->id,
            'center' => 'c'.$this->mxe['centerOne'],
            'from' => '2026-07',
            'to' => '2026-09',
        ])
        ->test('pages::reports.monthly-matrix')
        ->call('exportCsv');

    expect(mxeFile($page)['header'])->toEqual([
        [__('reports.matrix.proposal'), 'Grant Alpha'],
        [__('reports.matrix.city'), 'Aleppo'],
        [__('reports.matrix.program'), 'Education'],
        [__('reports.matrix.center'), 'Center One · Aleppo'],
        [__('reports.matrix.window'), 'Jul 2026 – Sep 2026'],
    ]);
});

test('with no filter the header block holds only the window', function () {
    $page = Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['from' => '2026-07', 'to' => '2026-10'])
        ->test('pages::reports.monthly-matrix')
        ->call('exportCsv');

    $file = mxeFile($page);

    expect($file['header'])->toEqual([[__('reports.matrix.window'), 'Jul 2026 – Oct 2026']])
        ->and($file['heading'])->toContain('Jul 2026')->toContain('Oct 2026')->not->toContain('Nov 2026')
        ->and($file['rows'])->toHaveCount(2);
});

test('the file has no amount column and one indicator name column in English', function () {
    $file = mxeFile(Livewire::actingAs(userWithRole('MEAL'))->test('pages::reports.monthly-matrix')->call('exportCsv'));
    $heading = strtolower(implode(',', $file['heading']));

    expect($heading)->not->toContain('amount')->not->toContain('budget')->not->toContain('currency')
        ->and($file['heading'])->toContain(__('reports.matrix.indicator'))
        ->and(implode(',', $file['rows'][0]))->toContain('Children enrolled')->not->toContain('الأطفال المسجلون');
});

test('an Arabic page exports Arabic labels, the Arabic name only and Arabic months', function () {
    $user = userWithRole('MEAL');
    $user->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');

    $file = mxeFile(Livewire::actingAs($user)
        ->withQueryParams(['city' => (string) $this->mxe['aleppo'], 'from' => '2026-07', 'to' => '2026-09'])
        ->test('pages::reports.monthly-matrix')
        ->call('exportCsv'));

    expect($file['header'])->toEqual([
        ['المدينة', 'حلب'],
        ['الأشهر', 'تموز 2026 – أيلول 2026'],
    ])
        ->and($file['heading'])->toContain(trans('reports.matrix.indicator', [], 'ar'))->toContain('آب 2026')
        ->and(implode(',', $file['rows'][0]))->toContain('الأطفال المسجلون')->not->toContain('Children enrolled');
});
