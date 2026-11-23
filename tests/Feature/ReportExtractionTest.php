<?php

use App\Enums\DonorAttribution;
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
use App\Models\ReportTemplateLine;
use App\Models\StrategicPillar;
use App\Support\Reporting\ExtractionFilter;
use App\Support\Reporting\ReportExtraction;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Y-05 (D-79, contracts §14.1): one extraction behind the matrix, its export and
| the monthly-reports list. One dataset, Jul–Oct 2026:
|
| Aleppo · split program · center 1   two templates (proposals 1 and 2): 10+20 (+5 submitted, +7 draft) and 3+4; a percentage 3/10, 5/10
| Aleppo · shared program · centers 1 and 2   one shared target (D-90, proposal 3, 200): 12+8 and 30
| Homs · shared program · field work (D-88)   proposal 2: 9
| Homs · split program · center not set (D-82)   proposal 3: 6
| Homs · split program · center 3   proposal 1: 2
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');

    Activity::withoutRecording(function (): void {
        $aleppo = insertHrCity('Aleppo');
        $homs = insertHrCity('Homs', 'حمص');
        $pillar = StrategicPillar::factory()->create();
        $split = Program::factory()->create(['donor_attribution' => DonorAttribution::Split]);
        $shared = Program::factory()->create(['donor_attribution' => DonorAttribution::Shared, 'strategic_pillar_id' => $pillar->id]);
        $centerOne = insertHrCenter($aleppo, 'Center One');
        $centerTwo = insertHrCenter($aleppo, 'Center Two');
        $centerThree = insertHrCenter($homs, 'Center Three');

        $donorOne = Donor::factory()->create();
        $donorTwo = Donor::factory()->create();
        $proposals = [
            1 => rxProposal($donorOne),
            2 => rxProposal($donorTwo),
            3 => rxProposal($donorOne),
        ];

        $count = LibraryIndicator::factory()->create();
        $percentage = LibraryIndicator::factory()->percentage()->create();

        $places = [
            'c1split' => new ReportPlace($aleppo, $split->id, $centerOne, false),
            'c1shared' => new ReportPlace($aleppo, $shared->id, $centerOne, false),
            'c2shared' => new ReportPlace($aleppo, $shared->id, $centerTwo, false),
            'field' => new ReportPlace($homs, $shared->id, null, true),
            'unset' => new ReportPlace($homs, $split->id, null, false),
            'c3split' => new ReportPlace($homs, $split->id, $centerThree, false),
        ];

        $templates = [
            'c1split-1' => rxTemplate($places['c1split'], $proposals[1]),
            'c1split-2' => rxTemplate($places['c1split'], $proposals[2]),
            'c1shared' => rxTemplate($places['c1shared'], $proposals[3]),
            'c2shared' => rxTemplate($places['c2shared'], $proposals[3]),
            'field' => rxTemplate($places['field'], $proposals[2]),
            'unset' => rxTemplate($places['unset'], $proposals[3]),
            'c3split' => rxTemplate($places['c3split'], $proposals[1]),
        ];

        $targets = [
            'c1split-1' => rxTarget($proposals[1], $count, '100', $places['c1split']),
            'c1split-2' => rxTarget($proposals[2], $count, '50', $places['c1split']),
            'c1split-pct' => rxTarget($proposals[1], $percentage, '50', $places['c1split']),
            'shared' => rxTarget($proposals[3], $count, '200', null, ['city_id' => $aleppo, 'program_id' => $shared->id, 'all_centers' => true]),
            'field' => rxTarget($proposals[2], $count, '80', $places['field']),
            'unset' => rxTarget($proposals[3], $count, '60', $places['unset']),
            'c3split' => rxTarget($proposals[1], $count, '40', $places['c3split']),
        ];

        foreach (['c1shared', 'c2shared'] as $holder) {
            ReportTemplateLine::factory()->create(['report_template_id' => $templates[$holder]->id, 'plan_indicator_id' => $targets['shared']->id]);
        }

        rxLine($templates['c1split-1'], '2026-07', $count, [$targets['c1split-1']], 10);
        rxLine($templates['c1split-1'], '2026-08', $count, [$targets['c1split-1']], 20);
        rxLine($templates['c1split-1'], '2026-09', $count, [$targets['c1split-1']], 5, MonthlyReportStatus::Submitted);
        rxLine($templates['c1split-1'], '2026-10', $count, [$targets['c1split-1']], 7, MonthlyReportStatus::Draft);
        rxLine($templates['c1split-1'], '2026-07', $percentage, [$targets['c1split-pct']], null, extra: ['numerator' => 3, 'denominator' => 10]);
        rxLine($templates['c1split-1'], '2026-08', $percentage, [$targets['c1split-pct']], null, extra: ['numerator' => 5, 'denominator' => 10]);
        rxLine($templates['c1split-2'], '2026-07', $count, [$targets['c1split-2']], 3);
        rxLine($templates['c1split-2'], '2026-10', $count, [$targets['c1split-2']], 4);
        rxLine($templates['c1shared'], '2026-08', $count, [$targets['shared']], 12);
        rxLine($templates['c1shared'], '2026-09', $count, [$targets['shared']], 8);
        rxLine($templates['c2shared'], '2026-08', $count, [$targets['shared']], 30);
        rxLine($templates['field'], '2026-09', $count, [$targets['field']], 9);
        rxLine($templates['unset'], '2026-10', $count, [$targets['unset']], 6);
        rxLine($templates['c3split'], '2026-07', $count, [$targets['c3split']], 2);

        $this->rx = compact('aleppo', 'homs', 'pillar', 'split', 'shared', 'centerOne', 'centerTwo', 'centerThree', 'donorOne', 'donorTwo', 'proposals', 'count', 'percentage', 'places', 'templates', 'targets');
    });
});

function rxProposal(Donor $donor): Proposal
{
    return Proposal::factory()->create([
        'donor_id' => $donor->id,
        'status' => ProposalStatus::Active,
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
    ]);
}

function rxTemplate(ReportPlace $place, Proposal $proposal): ReportTemplate
{
    return ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
}

/**
 * @param  array<string, mixed>  $columns  place columns instead of $place (a shared target)
 */
function rxTarget(Proposal $proposal, LibraryIndicator $library, string $target, ?ReportPlace $place, array $columns = []): PlanIndicator
{
    $indicator = Indicator::factory()->create(['library_indicator_id' => $library->id, 'measure' => $library->measure]);

    return PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => $indicator->id,
        'target' => $target,
        ...($place === null ? [] : ['city_id' => $place->cityId, 'program_id' => $place->programId, 'center_id' => $place->centerId, 'city_wide' => $place->cityWide]),
        ...$columns,
    ]);
}

/**
 * A line of the template's report of the month (made on first use), with one
 * share and one `reports` mirror per target, as the monthly report writers do.
 *
 * @param  list<PlanIndicator>  $targets
 * @param  array<string, mixed>  $extra
 */
function rxLine(ReportTemplate $template, string $month, LibraryIndicator $library, array $targets, ?float $value, MonthlyReportStatus $status = MonthlyReportStatus::Validated, array $extra = []): MonthlyReportLine
{
    return Activity::withoutRecording(function () use ($template, $month, $library, $targets, $value, $status, $extra): MonthlyReportLine {
        $period = CarbonImmutable::parse($month.'-01');
        $report = MonthlyReport::query()->where('report_template_id', $template->id)->whereDate('period', $period->toDateString())->first();

        if ($report === null) {
            $factory = match ($status) {
                MonthlyReportStatus::Draft => MonthlyReport::factory(),
                MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted(),
                MonthlyReportStatus::Validated => MonthlyReport::factory()->validated(),
            };
            $report = $factory->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => $period->toDateString()]);
        }

        $line = MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id, 'library_indicator_id' => $library->id, 'value' => $value, ...$extra]);

        foreach ($targets as $target) {
            $share = MonthlyReportLineShare::factory()->create(['monthly_report_line_id' => $line->id, 'plan_indicator_id' => $target->id, 'value' => $value, ...$extra]);

            Report::factory()->create([
                'plan_indicator_id' => $target->id,
                'status' => $report->status->mirrorStatus(),
                'planned_date' => $period->endOfMonth()->toDateString(),
                'period_start' => $period->toDateString(),
                'period_end' => $period->endOfMonth()->toDateString(),
                'monthly_report_id' => $report->id,
                'monthly_report_line_share_id' => $share->id,
                'value' => $value,
                'numerator' => $extra['numerator'] ?? null,
                'denominator' => $extra['denominator'] ?? null,
            ]);
        }

        return $line;
    });
}

function rxKey(ReportPlace $place, LibraryIndicator $library): string
{
    return ReportExtraction::placeKey($place->cityId, $place->programId, $place->centerId, $place->cityWide).'|'.$library->id;
}

/**
 * The center view's figures as key => [target, total].
 *
 * @return array<string, array{0: float|null, 1: float|null}>
 */
function rxCenter(ExtractionFilter $filter): array
{
    return collect(ReportExtraction::for($filter)->centerTotals())
        ->map(fn (array $row): array => [$row['target'], $row['total']])
        ->all();
}

/**
 * The donor view's figures as target id => [target, total].
 *
 * @return array<int, array{0: float|null, 1: float|null}>
 */
function rxDonor(ExtractionFilter $filter): array
{
    return collect(ReportExtraction::for($filter)->targetTotals())
        ->map(fn (array $row): array => [$row['target'], $row['total']])
        ->all();
}

test('with no filter every place is one row, two templates at one place are summed, and only validated figures count', function () {
    ['places' => $places, 'count' => $count, 'percentage' => $percentage] = $this->rx;

    expect(rxCenter(new ExtractionFilter(from: CarbonImmutable::parse('2026-07-01'), to: CarbonImmutable::parse('2026-10-01'))))->toEqual([
        rxKey($places['c1split'], $count) => [150.0, 37.0],
        rxKey($places['c1split'], $percentage) => [50.0, 80.0],
        rxKey($places['c1shared'], $count) => [200.0, 20.0],
        rxKey($places['c2shared'], $count) => [200.0, 30.0],
        rxKey($places['field'], $count) => [80.0, 9.0],
        rxKey($places['unset'], $count) => [60.0, 6.0],
        rxKey($places['c3split'], $count) => [40.0, 2.0],
    ]);
});

test('the library total counts a shared target once and sums its centers', function () {
    $totals = ReportExtraction::for(new ExtractionFilter(from: CarbonImmutable::parse('2026-07-01'), to: CarbonImmutable::parse('2026-10-01')))->libraryTotals();

    expect($totals[$this->rx['count']->id]['target'])->toEqual(530.0)
        ->and($totals[$this->rx['count']->id]['total'])->toEqual(104.0)
        ->and($totals[$this->rx['percentage']->id]['total'])->toEqual(80.0);
});

test('a shared target shows each center against the whole target, and one center filtered keeps the whole target', function () {
    ['places' => $places, 'count' => $count, 'targets' => $targets] = $this->rx;

    $rows = ReportExtraction::for(new ExtractionFilter)->centerTotals();

    expect($rows[rxKey($places['c1shared'], $count)]['shared_target_ids'])->toBe([$targets['shared']->id])
        ->and($rows[rxKey($places['c2shared'], $count)]['shared_target_ids'])->toBe([$targets['shared']->id])
        ->and(rxCenter(new ExtractionFilter(centerId: $this->rx['centerTwo'])))->toEqual([
            rxKey($places['c2shared'], $count) => [200.0, 30.0],
        ]);
});

test('each place filter alone narrows the center view', function (string $filter, array $expected) {
    ['places' => $places, 'count' => $count, 'percentage' => $percentage] = $this->rx;
    $all = [
        'c1split' => [rxKey($places['c1split'], $count), [150.0, 37.0]],
        'c1split-pct' => [rxKey($places['c1split'], $percentage), [50.0, 80.0]],
        'c1shared' => [rxKey($places['c1shared'], $count), [200.0, 20.0]],
        'c2shared' => [rxKey($places['c2shared'], $count), [200.0, 30.0]],
        'field' => [rxKey($places['field'], $count), [80.0, 9.0]],
        'unset' => [rxKey($places['unset'], $count), [60.0, 6.0]],
        'c3split' => [rxKey($places['c3split'], $count), [40.0, 2.0]],
    ];
    $arguments = match ($filter) {
        'city' => ['cityId' => $this->rx['homs']],
        'program' => ['programId' => $this->rx['shared']->id],
        'center' => ['centerId' => $this->rx['centerOne']],
        'field work' => ['cityId' => $this->rx['homs'], 'cityWide' => true],
        'center not set' => ['centerUnset' => true],
        'pillar' => ['pillarId' => $this->rx['pillar']->id],
        'indicator' => ['indicatorId' => $this->rx['percentage']->id],
    };

    expect(rxCenter(new ExtractionFilter(...$arguments)))
        ->toEqual(collect($expected)->mapWithKeys(fn (string $name): array => [$all[$name][0] => $all[$name][1]])->all());
})->with([
    'city' => ['city', ['field', 'unset', 'c3split']],
    'program' => ['program', ['c1shared', 'c2shared', 'field']],
    'center' => ['center', ['c1split', 'c1split-pct', 'c1shared']],
    'field work' => ['field work', ['field']],
    'center not set' => ['center not set', ['unset']],
    'pillar' => ['pillar', ['c1shared', 'c2shared', 'field']],
    'indicator' => ['indicator', ['c1split-pct']],
]);

test('a proposal or donor filter selects the donor view, read from the share mirrors', function () {
    ['targets' => $targets, 'proposals' => $proposals] = $this->rx;

    expect((new ExtractionFilter(proposalId: 1))->isDonorView())->toBeTrue()
        ->and((new ExtractionFilter(cityId: 1))->isDonorView())->toBeFalse()
        ->and(rxDonor(new ExtractionFilter(proposalId: $proposals[3]->id)))->toEqual([
            $targets['shared']->id => [200.0, 50.0],
            $targets['unset']->id => [60.0, 6.0],
        ])
        ->and(rxDonor(new ExtractionFilter(donorId: $this->rx['donorTwo']->id)))->toEqual([
            $targets['c1split-2']->id => [50.0, 7.0],
            $targets['field']->id => [80.0, 9.0],
        ]);
});

test('proposal and center together show that center\'s figure against the whole shared target', function () {
    ['targets' => $targets, 'proposals' => $proposals] = $this->rx;

    expect(rxDonor(new ExtractionFilter(proposalId: $proposals[3]->id, centerId: $this->rx['centerOne'])))->toEqual([
        $targets['shared']->id => [200.0, 20.0],
    ]);
});

test('city, program and a range that excludes a month narrow together', function () {
    ['places' => $places, 'count' => $count, 'percentage' => $percentage] = $this->rx;

    $extraction = ReportExtraction::for(new ExtractionFilter(cityId: $this->rx['aleppo'], programId: $this->rx['split']->id, from: CarbonImmutable::parse('2026-08-14'), to: CarbonImmutable::parse('2026-10-31')));

    expect($extraction->months())->toEqual([CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01')])
        ->and(collect($extraction->centerTotals())->map(fn (array $row): array => [$row['target'], $row['total']])->all())->toEqual([
            rxKey($places['c1split'], $count) => [150.0, 24.0],
            rxKey($places['c1split'], $percentage) => [50.0, 50.0],
        ]);
});

test('an open window runs from the first reported month to this month', function () {
    expect(ReportExtraction::for(new ExtractionFilter)->months())->toEqual([
        CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01'),
    ]);
});

test('my places follow the viewer\'s MEAL coverage', function () {
    ['places' => $places, 'count' => $count] = $this->rx;
    $employee = insertHrEmployee();
    $place = $places['c3split'];
    DB::table('meal_coverages')->insert([
        'scope_type' => 'place',
        'scope_key' => 'place:'.$place->key(),
        'city_id' => $place->cityId,
        'program_id' => $place->programId,
        'center_id' => $place->centerId,
        'city_wide' => $place->cityWide,
        'employee_id' => $employee,
    ]);

    $this->actingAs(userWithRole('MEAL', $employee));

    expect(rxCenter(new ExtractionFilter(mine: true)))->toEqual([rxKey($place, $count) => [40.0, 2.0]]);

    $this->actingAs(userWithRole('MEAL', insertHrEmployee()));

    expect(rxCenter(new ExtractionFilter(mine: true)))->toBe([]);
});

test('a filter that matches nothing is empty in both views', function () {
    expect(rxCenter(new ExtractionFilter(centerId: $this->rx['centerTwo'], programId: $this->rx['split']->id)))->toBe([])
        ->and(rxDonor(new ExtractionFilter(proposalId: $this->rx['proposals'][2]->id, centerId: $this->rx['centerTwo'])))->toBe([]);
});

test('the lines, shares and reports readers take the same filters', function () {
    $filter = new ExtractionFilter(proposalId: $this->rx['proposals'][3]->id, centerId: $this->rx['centerOne']);
    $extraction = ReportExtraction::for($filter);

    expect($extraction->lines()->pluck('value')->map(fn (mixed $value): float => (float) $value)->sort()->values()->all())->toBe([8.0, 12.0])
        ->and($extraction->shares()->count())->toBe(2)
        ->and($extraction->reports()->count())->toBe(2);
});

test('the figures take a fixed number of queries whatever the number of places and months', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $extraction = ReportExtraction::for(new ExtractionFilter);
        $extraction->centerTotals();
        $extraction->libraryTotals();
        $extraction->targetTotals();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $before = $count();

    Activity::withoutRecording(function (): void {
        foreach (['Center Four', 'Center Five'] as $name) {
            $place = new ReportPlace($this->rx['homs'], $this->rx['split']->id, insertHrCenter($this->rx['homs'], $name), false);
            $template = rxTemplate($place, $this->rx['proposals'][1]);
            $target = rxTarget($this->rx['proposals'][1], $this->rx['count'], '10', $place);

            foreach (['2026-03', '2026-04', '2026-05'] as $month) {
                rxLine($template, $month, $this->rx['count'], [$target], 1);
            }
        }
    });

    expect($count())->toBe($before);
});

test('a shared ratio target shows each center its own pooled figure and pools the centers in every total', function () {
    ['places' => $places, 'templates' => $templates, 'proposals' => $proposals] = $this->rx;

    $library = LibraryIndicator::factory()->percentage()->create();
    $shared = Activity::withoutRecording(function () use ($library, $templates, $proposals): PlanIndicator {
        $target = rxTarget($proposals[3], $library, '50', null, ['city_id' => $this->rx['aleppo'], 'program_id' => $this->rx['shared']->id, 'all_centers' => true]);

        foreach (['c1shared', 'c2shared'] as $holder) {
            ReportTemplateLine::factory()->create(['report_template_id' => $templates[$holder]->id, 'plan_indicator_id' => $target->id]);
        }

        return $target;
    });

    rxLine($templates['c1shared'], '2026-08', $library, [$shared], null, extra: ['numerator' => 3, 'denominator' => 10]);
    rxLine($templates['c1shared'], '2026-09', $library, [$shared], null, extra: ['numerator' => 6, 'denominator' => 10]);
    rxLine($templates['c2shared'], '2026-08', $library, [$shared], null, extra: ['numerator' => 4, 'denominator' => 20]);

    [$from, $to] = [CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-10-01')];
    $extraction = ReportExtraction::for(new ExtractionFilter(from: $from, to: $to));

    // Fixed basis: Σ the center's counts over its latest denominator; never the other center's figures.
    expect($extraction->centerTotals()[rxKey($places['c1shared'], $library)]['total'])->toEqual(90.0)
        ->and($extraction->centerTotals()[rxKey($places['c2shared'], $library)]['total'])->toEqual(20.0)
        ->and($extraction->libraryTotals()[$library->id]['total'])->toEqual(43.33) // (9 + 4) ÷ (10 + 20)
        ->and(ReportExtraction::for(new ExtractionFilter(proposalId: $proposals[3]->id, from: $from, to: $to))->targetTotals()[$shared->id]['total'])->toEqual(43.33)
        ->and(ReportExtraction::for(new ExtractionFilter(centerId: $this->rx['centerTwo'], from: $from, to: $to))->libraryTotals()[$library->id]['total'])->toEqual(20.0)
        ->and($extraction->targetRows()->firstWhere('plan_id', $shared->id)->shared_centers)->toEqual(2);
});
