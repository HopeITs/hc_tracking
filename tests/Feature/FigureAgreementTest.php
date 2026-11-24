<?php

declare(strict_types=1);

use App\Enums\DonorAttribution;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\User;
use App\Support\DonorReportAggregator;
use App\Support\Reporting\ExtractionFilter;
use App\Support\Reporting\ReportExtraction;
use App\Support\StrategyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Y-16, Build order step 1: the figures this task must not change.
|
| Every assertion here is an explicit number on a dataset built from scratch,
| so moving a figure from a project key to a place key (contracts §14,
| "Centers in figures") fails loudly rather than drifting. The last test is the
| two-proposal case: one place reached by two rows, where the place figure is
| the sum of its rows.
|
| The dataset is deliberately one-row-per-project, which is what the prompt
| calls the agreement case.
*/

beforeEach(fn () => $this->travelTo('2026-11-13 10:00:00'));

/**
 * One place: a program, a city, a centre, an active project and a
 * donor-approved proposal carrying it through one location row, with a
 * confirmed report template at that place.
 *
 * @return array{program: Program, project: Project, proposal: Proposal, row: ProposalLocation, template: ReportTemplate, cityId: int, centerId: int, donor: Donor}
 */
function faPlace(
    ?Strategy $strategy = null,
    ?Donor $donor = null,
    DonorAttribution $attribution = DonorAttribution::Shared,
    string $begin = '2026-01-01',
    string $end = '2026-12-31',
    string $proposalName = 'Winter WASH',
): array {
    return Activity::withoutRecording(function () use ($strategy, $donor, $attribution, $begin, $end, $proposalName): array {
        $program = Program::factory()->create([
            'donor_attribution' => $attribution,
            'strategy_id' => $strategy?->id,
        ]);

        $cityId = insertHrCity('Aleppo '.fake()->unique()->randomNumber(5));
        $centerId = insertHrCenter($cityId, 'Centre '.fake()->unique()->randomNumber(5));

        $project = Project::factory()->active()->create([
            'program_id' => $program->id,
            'city_id' => $cityId,
            'center_id' => $centerId,
            'start_date' => $begin,
            'end_date' => $end,
        ]);

        $proposal = Proposal::factory()->create([
            'name' => $proposalName,
            'donor_id' => ($donor ?? Donor::factory()->create())->id,
            'status' => ProposalStatus::Active,
            'actual_date_begin' => $begin,
            'actual_date_end' => $end,
        ]);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

        $row = ProposalLocation::query()->where('proposal_id', $proposal->id)->where('project_id', $project->id)->first()
            ?? ProposalLocation::factory()->create([
                'proposal_id' => $proposal->id,
                'project_id' => $project->id,
                'city_id' => $cityId,
                'program_id' => $program->id,
            ]);

        if (! $row->centers()->exists()) {
            $row->centers()->create(['center_id' => $centerId]);
        }

        $template = ReportTemplate::query()->where('project_id', $project->id)->first()
            ?? ReportTemplate::factory()->create([
                'project_id' => $project->id,
                'city_id' => $cityId,
                'program_id' => $program->id,
                'center_id' => $centerId,
            ]);

        return compact('program', 'project', 'proposal', 'row', 'template', 'cityId', 'centerId') + ['donor' => $proposal->donor];
    });
}

/**
 * A numeric target of the proposal at the row's place, on a library entry.
 */
function faTarget(array $place, LibraryIndicator $library, string $target = '100'): PlanIndicator
{
    return Activity::withoutRecording(function () use ($place, $library, $target): PlanIndicator {
        $indicator = Indicator::factory()->create([
            'library_indicator_id' => $library->id,
            'measure' => $library->measure,
            'result_id' => Result::factory()->create(['program_id' => $place['program']->id, 'proposal_id' => $place['proposal']->id])->id,
            'proposal_location_id' => $place['row']->id,
            'project_id' => $place['project']->id,
        ]);

        return PlanIndicator::factory()->forProposal($place['proposal'])->create([
            'indicator_id' => $indicator->id,
            'proposal_location_id' => $place['row']->id,
            'project_id' => $place['project']->id,
            'city_id' => $place['cityId'],
            'program_id' => $place['program']->id,
            'center_id' => $place['centerId'],
            'city_wide' => false,
            'target' => $target,
        ]);
    });
}

/**
 * A validated monthly report of the place's template for that month, with one
 * line on the library entry and one share (and its mirror) per target.
 *
 * @param  list<PlanIndicator>  $targets
 */
function faValidatedLine(array $place, LibraryIndicator $library, array $targets, float $value, string $month): MonthlyReportLine
{
    return Activity::withoutRecording(function () use ($place, $library, $targets, $value, $month): MonthlyReportLine {
        $period = CarbonImmutable::parse($month)->startOfMonth();

        $report = MonthlyReport::query()
            ->where('report_template_id', $place['template']->id)
            ->where('period', $period->toDateString())
            ->first()
            ?? MonthlyReport::factory()->validated()->create([
                'project_id' => $place['project']->id,
                'report_template_id' => $place['template']->id,
                'period' => $period->toDateString(),
            ]);

        $line = MonthlyReportLine::factory()->create([
            'monthly_report_id' => $report->id,
            'library_indicator_id' => $library->id,
            'value' => $value,
        ]);

        foreach ($targets as $target) {
            $share = MonthlyReportLineShare::factory()->create([
                'monthly_report_line_id' => $line->id,
                'plan_indicator_id' => $target->id,
                'value' => $value,
            ]);

            Report::factory()->create([
                'plan_indicator_id' => $target->id,
                'status' => MonthlyReportStatus::Validated->mirrorStatus(),
                'planned_date' => $period->endOfMonth()->toDateString(),
                'period_start' => $period->toDateString(),
                'period_end' => $period->endOfMonth()->toDateString(),
                'monthly_report_id' => $report->id,
                'monthly_report_line_share_id' => $share->id,
                'value' => $value,
            ]);
        }

        return $line;
    });
}

/** The place key the centre view groups by (contracts §14.1). */
function faKey(array $place): string
{
    return ReportExtraction::placeKey($place['cityId'], $place['program']->id, $place['centerId'], false);
}

function faViewer(): User
{
    return User::factory()->role('General Manager')->create();
}

test('the centre view totals a place by its template, not by its project', function () {
    $library = LibraryIndicator::factory()->create();
    $place = faPlace();
    $target = faTarget($place, $library, '500');

    faValidatedLine($place, $library, [$target], 120.0, '2026-06-01');
    faValidatedLine($place, $library, [$target], 80.0, '2026-07-01');

    $extraction = ReportExtraction::for(new ExtractionFilter(
        from: CarbonImmutable::parse('2026-06-01'),
        to: CarbonImmutable::parse('2026-07-31'),
    ));

    $key = faKey($place).'|'.$library->id;
    $centres = $extraction->centerTotals();

    expect($centres)->toHaveKey($key)
        ->and((float) $centres[$key]['total'])->toBe(200.0)
        ->and((float) $centres[$key]['target'])->toBe(500.0)
        ->and((float) $centres[$key]['achieved'])->toBe(40.0)
        ->and($centres[$key]['place']->cityId)->toBe($place['cityId'])
        ->and($centres[$key]['place']->centerId)->toBe($place['centerId']);
});

test('the donor view totals the target', function () {
    $library = LibraryIndicator::factory()->create();
    $place = faPlace();
    $target = faTarget($place, $library, '500');

    faValidatedLine($place, $library, [$target], 120.0, '2026-06-01');
    faValidatedLine($place, $library, [$target], 80.0, '2026-07-01');

    $donorView = ReportExtraction::for(new ExtractionFilter(
        donorId: $place['donor']->id,
        from: CarbonImmutable::parse('2026-06-01'),
        to: CarbonImmutable::parse('2026-07-31'),
    ));

    $row = $donorView->targetTotals()[$target->id];

    expect((float) $row['target'])->toBe(500.0)
        ->and((float) $row['total'])->toBe(200.0)
        ->and($target->refresh()->achievedValue())->toBe(200.0);
});

test('the donor report aggregator reports the same achieved figure per centre', function () {
    $library = LibraryIndicator::factory()->create();
    $place = faPlace();
    $target = faTarget($place, $library, '500');

    faValidatedLine($place, $library, [$target], 120.0, '2026-06-01');
    faValidatedLine($place, $library, [$target], 80.0, '2026-07-01');

    $snapshot = app(DonorReportAggregator::class)->aggregate(
        $place['proposal']->refresh(),
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
        false,
    );

    $row = collect($snapshot['indicators'])->firstWhere('indicator_id', $target->indicator_id);
    $centre = collect($row['centers'])->firstWhere('plan_indicator_id', $target->id);

    // The shape is frozen (the prompt forbids changing it): one indicator row
    // with one centre under it, the period figure, the cumulative figure and
    // the achievement percentage.
    expect($snapshot['centers'])->toHaveCount(1)
        ->and((float) $row['target'])->toBe(500.0)
        ->and((float) $row['period']['value'])->toBe(200.0)
        ->and((float) $row['cumulative']['value'])->toBe(200.0)
        ->and((float) $row['achievement'])->toBe(40.0)
        ->and((float) $centre['target'])->toBe(500.0)
        ->and((float) $centre['period']['value'])->toBe(200.0)
        ->and((int) $centre['beneficiaries'])->toBe(100);
});

test('the strategy rollup counts a late report under its program strategy', function () {
    $strategy = Strategy::factory()->create();
    $library = LibraryIndicator::factory()->create();
    $place = faPlace(strategy: $strategy);
    $target = faTarget($place, $library, '500');

    // A draft report past its planned date is late (Report::overdue()).
    Activity::withoutRecording(fn () => Report::factory()->create([
        'plan_indicator_id' => $target->id,
        'status' => ReportStatus::Draft,
        'planned_date' => '2026-08-31',
        'value' => null,
    ]));

    $rollup = StrategyRollup::for(faViewer(), [$strategy->id]);

    expect($rollup[$strategy->id]['late'])->toBe(['reports' => 1, 'total' => 1]);
});

test('the strategy rollup funding reads the proposals of the strategy programs', function () {
    $strategy = Strategy::factory()->create();
    $place = faPlace(strategy: $strategy);

    DB::table('proposals')->where('id', $place['proposal']->id)->update([
        'requested_budget' => '1000.00',
        'requested_currency' => 'USD',
    ]);

    $rollup = StrategyRollup::for(faViewer(), [$strategy->id]);
    $usd = collect($rollup[$strategy->id]['funding']['lines'])->firstWhere('currency', 'USD');

    expect((float) $usd['requested'])->toBe(1000.0);
});

test('two proposals at one place: the place figure is the sum of its rows', function () {
    $library = LibraryIndicator::factory()->create();

    // One place, two proposals. The second proposal gets its own row and its
    // own target at the same city, program and centre (D-77), and its own
    // template — two templates at one place are one centre-view row (§14.1).
    $first = faPlace(proposalName: 'First grant');
    $firstTarget = faTarget($first, $library, '300');

    $secondProposal = Activity::withoutRecording(function () use ($first): Proposal {
        $proposal = Proposal::factory()->create([
            'name' => 'Second grant',
            'donor_id' => Donor::factory()->create()->id,
            'status' => ProposalStatus::Active,
            'actual_date_begin' => '2026-01-01',
            'actual_date_end' => '2026-12-31',
        ]);
        $proposal->projects()->attach($first['project']->id, ['target_beneficiaries' => 100]);

        return $proposal;
    });

    $secondRow = ProposalLocation::query()
        ->where('proposal_id', $secondProposal->id)
        ->where('project_id', $first['project']->id)
        ->first()
        ?? Activity::withoutRecording(fn (): ProposalLocation => ProposalLocation::factory()->create([
            'proposal_id' => $secondProposal->id,
            'project_id' => $first['project']->id,
            'city_id' => $first['cityId'],
            'program_id' => $first['program']->id,
        ]));

    if (! $secondRow->centers()->exists()) {
        $secondRow->centers()->create(['center_id' => $first['centerId']]);
    }

    $second = [...$first, 'proposal' => $secondProposal, 'row' => $secondRow];
    $secondTarget = faTarget($second, $library, '200');

    // One place means one template and one report per month, so the two rows'
    // targets are two shares of the SAME line (§13): 120 + 80 reported once.
    faValidatedLine($first, $library, [$firstTarget, $secondTarget], 200.0, '2026-06-01');

    $extraction = ReportExtraction::for(new ExtractionFilter(
        from: CarbonImmutable::parse('2026-06-01'),
        to: CarbonImmutable::parse('2026-06-30'),
    ));

    $key = faKey($first).'|'.$library->id;
    $centres = $extraction->centerTotals();

    // One centre-view row for the place, carrying both rows' targets, and the
    // figure reported once there (§14 "Center target": `shared` takes the
    // highest of the two targets, so 300, not 500).
    expect($centres)->toHaveCount(1)
        ->and($centres[$key]['target_ids'])->toHaveCount(2)
        ->and((float) $centres[$key]['total'])->toBe(200.0)
        ->and((float) $centres[$key]['target'])->toBe(300.0);
});
