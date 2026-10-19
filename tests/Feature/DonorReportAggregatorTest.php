<?php

use App\Enums\ActivityStatus;
use App\Enums\ArStatus;
use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\Gender;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportingPeriod;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineBreakdown;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\Result;
use App\Models\TransfersTracking;
use App\Support\DonorReportAggregator;
use App\Support\DonorReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| The donor report aggregator (contracts §14, §14.1, §14.3; D-25, D-40/D-46,
| D-50, D-51): only validated share mirrors count, ratios pool through
| PlanIndicator::pooledRatio(), and finance is read only when asked for.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
});

/**
 * @return array<string, mixed>
 */
function draAggregate(Proposal $proposal, string $start = '2026-01-01', string $end = '2026-03-31', bool $finance = false): array
{
    return app(DonorReportAggregator::class)->aggregate($proposal->fresh(), CarbonImmutable::parse($start), CarbonImmutable::parse($end), $finance);
}

/**
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function draRow(array $snapshot, int $indicatorId, string $section = 'indicators'): array
{
    return collect($snapshot[$section])->firstWhere('indicator_id', $indicatorId);
}

describe('periods', function () {
    test('fixed periods run from the start month and clip at the grant end', function () {
        $proposal = donorReportProposal(['actual_date_begin' => '2026-01-10', 'actual_date_end' => '2026-08-15']);
        $window = fn (ReportingPeriod $type, string $start, ?string $end = null): array => array_map(
            fn (CarbonImmutable $date): string => $date->toDateString(),
            DonorReportPeriods::window($proposal, $type, $start, $end),
        );

        expect($window(ReportingPeriod::Quarterly, '2026-01'))->toBe(['start' => '2026-01-01', 'end' => '2026-03-31'])
            ->and($window(ReportingPeriod::Quarterly, '2026-07'))->toBe(['start' => '2026-07-01', 'end' => '2026-08-15'])
            ->and($window(ReportingPeriod::SemiAnnual, '2026-01'))->toBe(['start' => '2026-01-01', 'end' => '2026-06-30'])
            ->and($window(ReportingPeriod::Annual, '2026-01'))->toBe(['start' => '2026-01-01', 'end' => '2026-08-15'])
            ->and($window(ReportingPeriod::Custom, '2026-02', '2026-04'))->toBe(['start' => '2026-02-01', 'end' => '2026-04-30']);
    });

    test('a period outside the grant is refused', function (string $start, ?string $end, ReportingPeriod $type) {
        $proposal = donorReportProposal(['actual_date_begin' => '2026-01-10', 'actual_date_end' => '2026-08-15']);

        expect(fn () => DonorReportPeriods::window($proposal, $type, $start, $end))
            ->toThrow(ValidationException::class, __('donor_reports.period_outside_grant'));
    })->with([
        'before the grant' => ['2025-12', null, ReportingPeriod::Quarterly],
        'after the grant' => ['2026-09', null, ReportingPeriod::Quarterly],
        'custom end before start' => ['2026-04', '2026-03', ReportingPeriod::Custom],
        'custom end after the grant' => ['2026-06', '2026-09', ReportingPeriod::Custom],
        'custom without end' => ['2026-06', null, ReportingPeriod::Custom],
    ]);
});

describe('figures', function () {
    test('only validated mirrors count; a submitted month is excluded and listed as incomplete', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->create();
        $target = donorReportTarget($proposal, $center, $library, '100');

        donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => 10]]);
        donorReportFigures($center, '2026-02', $library, [$target->id => ['value' => 20]]);
        donorReportFigures($center, '2026-03', $library, [$target->id => ['value' => 99]], MonthlyReportStatus::Submitted);
        donorReportMonth(donorReportCenter($proposal), '2026-03', MonthlyReportStatus::Draft);

        $snapshot = draAggregate($proposal);
        $row = draRow($snapshot, $target->indicator_id);

        expect($snapshot['schema_version'])->toBe(1)
            ->and($row['period']['value'])->toEqual(30.0)
            ->and($row['cumulative']['value'])->toEqual(30.0)
            ->and($row['achievement'])->toEqual(30.0)
            ->and($snapshot['completeness']['has_validated_data'])->toBeTrue()
            ->and(collect($snapshot['completeness']['missing'])->map(fn (array $item): string => $item['month'].' '.$item['state'])->all())
            ->toEqualCanonicalizing(['2026-03 not_validated', '2026-03 not_submitted']);
    });

    test('cumulative to period end starts at the grant start', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->create();
        $target = donorReportTarget($proposal, $center, $library, '200');

        donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => 10]]);
        donorReportFigures($center, '2026-04', $library, [$target->id => ['value' => 30]]);

        $row = draRow(draAggregate($proposal, '2026-04-01', '2026-06-30'), $target->indicator_id);

        expect($row['period']['value'])->toEqual(30.0)
            ->and($row['cumulative']['value'])->toEqual(40.0)
            ->and($row['achievement'])->toEqual(20.0);
    });

    test('percentages pool across centers, never average', function () {
        $proposal = donorReportProposal();
        $library = LibraryIndicator::factory()->percentage()->create(['denominator_basis' => DenominatorBasis::Entered]);
        $a = donorReportCenter($proposal);
        $b = donorReportCenter($proposal);
        $targetA = donorReportTarget($proposal, $a, $library, '80');
        $targetB = donorReportTarget($proposal, $b, $library, '80');

        donorReportFigures($a, '2026-02', $library, [$targetA->id => ['value' => 90, 'numerator' => 9, 'denominator' => 10]]);
        donorReportFigures($b, '2026-02', $library, [$targetB->id => ['value' => 50, 'numerator' => 1, 'denominator' => 2]]);

        $row = draRow(draAggregate($proposal), $targetA->indicator_id);

        expect(round($row['period']['value'], 1))->toBe(83.3)
            ->and($row['period']['numerator'])->toEqual(10.0)
            ->and($row['period']['denominator'])->toEqual(12.0)
            ->and(collect($row['centers'])->pluck('period.value', 'project_id')->map(fn (float $value): float => round($value, 1))->all())
            ->toEqual([$a->id => 90.0, $b->id => 50.0]);
    });

    test('a fixed-denominator percentage is the sum of counts over the sum of beneficiaries', function () {
        $proposal = donorReportProposal();
        $library = LibraryIndicator::factory()->percentage()->create();
        $a = donorReportCenter($proposal, beneficiaries: 100);
        $b = donorReportCenter($proposal, beneficiaries: 50);
        $targetA = donorReportTarget($proposal, $a, $library, '50');
        $targetB = donorReportTarget($proposal, $b, $library, '50');

        donorReportFigures($a, '2026-01', $library, [$targetA->id => ['value' => 10, 'numerator' => 10, 'denominator' => 100, 'fixed' => 100]]);
        donorReportFigures($a, '2026-02', $library, [$targetA->id => ['value' => 20, 'numerator' => 20, 'denominator' => 100, 'fixed' => 100]]);
        donorReportFigures($b, '2026-02', $library, [$targetB->id => ['value' => 30, 'numerator' => 15, 'denominator' => 50, 'fixed' => 50]]);

        $row = draRow(draAggregate($proposal), $targetA->indicator_id);

        expect($row['period']['numerator'])->toEqual(45.0)
            ->and($row['period']['denominator'])->toEqual(150.0)
            ->and($row['period']['value'])->toEqual(30.0)
            ->and(collect($row['centers'])->pluck('beneficiaries', 'project_id')->all())->toEqual([$a->id => 100, $b->id => 50]);
    });

    test('a cumulative count is the latest validated value, never a sum', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->create(['is_cumulative' => true]);
        $target = donorReportTarget($proposal, $center, $library, '50');

        foreach (['2026-01' => 10, '2026-02' => 25, '2026-03' => 40] as $month => $value) {
            donorReportFigures($center, $month, $library, [$target->id => ['value' => $value]]);
        }

        $row = draRow(draAggregate($proposal), $target->indicator_id);

        expect($row['period']['value'])->toEqual(40.0)
            ->and($row['achievement'])->toEqual(80.0);
    });

    test('a tracking-only indicator shows its value without achievement', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->create();
        $target = donorReportTarget($proposal, $center, $library, '100', ['is_tracking_only' => true]);

        donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => 12]]);

        $row = draRow(draAggregate($proposal), $target->indicator_id);

        expect($row['period']['value'])->toEqual(12.0)
            ->and($row['achievement'])->toBeNull()
            ->and($row['centers'][0]['achievement'])->toBeNull();
    });

    test('a target whose template line was retired goes to the historical section', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->create();
        $target = donorReportTarget($proposal, $center, $library);

        donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => 7]]);
        Activity::withoutRecording(fn () => ReportTemplateLine::factory()->retired()->create([
            'report_template_id' => ReportTemplate::factory()->create(['project_id' => $center->id])->id,
            'plan_indicator_id' => $target->id,
        ]));

        $snapshot = draAggregate($proposal);

        expect(collect($snapshot['indicators'])->pluck('indicator_id'))->not->toContain($target->indicator_id)
            ->and(draRow($snapshot, $target->indicator_id, 'historical')['period']['value'])->toEqual(7.0);
    });

    test('a ratio without a denominator is left out and listed', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->percentage()->create(['denominator_basis' => DenominatorBasis::Entered]);
        $target = donorReportTarget($proposal, $center, $library, '80');

        donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => null, 'numerator' => null, 'denominator' => null]]);

        $snapshot = draAggregate($proposal);

        expect(draRow($snapshot, $target->indicator_id)['period']['value'])->toBeNull()
            ->and($snapshot['completeness']['missing_denominator'])->toBe([['plan_indicator_id' => $target->id, 'project_id' => $center->id]]);
    });

    test('an approved MEAL line in the period is included', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $library = LibraryIndicator::factory()->create(['collected_by' => 'meal']);
        $target = donorReportTarget($proposal, $center, $library);

        donorReportFigures($center, '2026-03', $library, [$target->id => ['value' => 5]]);
        MonthlyReportLine::query()->update(['entered_by' => 'meal']);

        expect(draRow(draAggregate($proposal), $target->indicator_id)['period']['value'])->toEqual(5.0);
    });
});

describe('donor attribution', function () {
    test('split gives each donor its own share, without the shared footnote', function () {
        $first = donorReportProposal();
        $second = donorReportProposal();
        $program = Program::factory()->create(['donor_attribution' => DonorAttribution::Split]);
        $center = donorReportCenter($first, program: $program);
        $second->projects()->attach($center->id, ['target_beneficiaries' => 40]);
        $library = LibraryIndicator::factory()->create();
        $targetFirst = donorReportTarget($first, $center, $library);
        $targetSecond = donorReportTarget($second, $center, $library);

        foreach (['2026-01', '2026-02', '2026-03'] as $month) {
            donorReportFigures($center, $month, $library, [$targetFirst->id => ['value' => 6], $targetSecond->id => ['value' => 4]]);
        }

        $rowFirst = draRow(draAggregate($first), $targetFirst->indicator_id);
        $rowSecond = draRow(draAggregate($second), $targetSecond->indicator_id);

        expect($rowFirst['period']['value'])->toEqual(18.0)
            ->and($rowSecond['period']['value'])->toEqual(12.0)
            ->and($rowFirst['shared'])->toBeFalse();
    });

    test('shared counts fully for each donor, with the shared footnote', function () {
        $first = donorReportProposal();
        $second = donorReportProposal();
        $program = Program::factory()->create(['donor_attribution' => DonorAttribution::Shared]);
        $center = donorReportCenter($first, program: $program);
        $second->projects()->attach($center->id, ['target_beneficiaries' => 40]);
        $library = LibraryIndicator::factory()->create();
        $targetFirst = donorReportTarget($first, $center, $library);
        $targetSecond = donorReportTarget($second, $center, $library);

        foreach (['2026-01', '2026-02', '2026-03'] as $month) {
            donorReportFigures($center, $month, $library, [$targetFirst->id => ['value' => 30], $targetSecond->id => ['value' => 30]]);
        }

        $rowFirst = draRow(draAggregate($first), $targetFirst->indicator_id);
        $rowSecond = draRow(draAggregate($second), $targetSecond->indicator_id);

        expect($rowFirst['period']['value'])->toEqual(90.0)
            ->and($rowSecond['period']['value'])->toEqual(90.0)
            ->and($rowFirst['shared'])->toBeTrue();
    });
});

describe('sections', function () {
    test('disaggregation sums validated breakdowns per center and for the proposal', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal, DonorAttribution::Split);
        $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
        $target = donorReportTarget($proposal, $center, $library);

        foreach (['2026-01' => MonthlyReportStatus::Validated, '2026-02' => MonthlyReportStatus::Validated, '2026-03' => MonthlyReportStatus::Submitted] as $month => $status) {
            $share = donorReportFigures($center, $month, $library, [$target->id => ['value' => 10]], $status)[$target->id];
            MonthlyReportLineBreakdown::factory()->create(['monthly_report_line_id' => $share->monthly_report_line_id, 'monthly_report_line_share_id' => $share->id, 'gender' => Gender::Male, 'value' => 6, 'disabled_value' => 1]);
            MonthlyReportLineBreakdown::factory()->create(['monthly_report_line_id' => $share->monthly_report_line_id, 'monthly_report_line_share_id' => $share->id, 'gender' => Gender::Female, 'value' => 4, 'disabled_value' => 0]);
        }

        $disaggregation = draRow(draAggregate($proposal), $target->indicator_id)['disaggregation'];

        expect($disaggregation['total'])->toEqual([
            'male' => ['value' => 12.0], 'male_disabled' => ['value' => 2.0],
            'female' => ['value' => 8.0], 'female_disabled' => ['value' => 0.0],
            'total' => ['value' => 20.0],
        ])->and($disaggregation['centers'][0]['project_id'])->toBe($center->id)
            ->and($disaggregation['centers'][0]['male']['value'])->toEqual(12.0);
    });

    test('shared mode reads the line-level breakdown', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal, DonorAttribution::Shared);
        $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
        $target = donorReportTarget($proposal, $center, $library);

        $share = donorReportFigures($center, '2026-01', $library, [$target->id => ['value' => 10]])[$target->id];
        MonthlyReportLineBreakdown::factory()->create(['monthly_report_line_id' => $share->monthly_report_line_id, 'monthly_report_line_share_id' => null, 'gender' => Gender::Female, 'value' => 10, 'disabled_value' => 3]);

        $total = draRow(draAggregate($proposal), $target->indicator_id)['disaggregation']['total'];

        expect($total['female'])->toEqual(['value' => 10.0])
            ->and($total['female_disabled'])->toEqual(['value' => 3.0]);
    });

    test('activities show their status at period end and their execution months', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposal->id, 'program_id' => null]);
        $activity = Result::factory()->activity($output)->create();

        foreach ([['2026-01', ActivityStatus::Started, MonthlyReportStatus::Validated], ['2026-02', ActivityStatus::Ongoing, MonthlyReportStatus::Validated], ['2026-03', ActivityStatus::Completed, MonthlyReportStatus::Submitted]] as [$month, $status, $reportStatus]) {
            MonthlyReportActivityEntry::factory()->create(['monthly_report_id' => donorReportMonth($center, $month, $reportStatus)->id, 'result_id' => $activity->id, 'status' => $status]);
        }

        $row = collect(draAggregate($proposal)['activities'])->firstWhere('result_id', $activity->id);

        expect($row['centers'])->toBe([[
            'project_id' => $center->id,
            'status' => 'ongoing',
            'execution_months' => ['2026-01', '2026-02'],
        ]]);
    });

    test('narratives and file links come from validated reports only', function () {
        $proposal = donorReportProposal();
        $center = donorReportCenter($proposal);
        $validated = donorReportMonth($center, '2026-01');
        $submitted = donorReportMonth($center, '2026-02', MonthlyReportStatus::Submitted);

        MonthlyReportAnswer::factory()->create(['monthly_report_id' => $validated->id, 'question_text' => 'Q1', 'answer' => 'Yes']);
        MonthlyReportAnswer::factory()->create(['monthly_report_id' => $submitted->id, 'question_text' => 'Q2', 'answer' => 'No']);
        $file = MonthlyReportFile::factory()->create(['monthly_report_id' => $validated->id]);
        MonthlyReportFile::factory()->create(['monthly_report_id' => $submitted->id]);

        $snapshot = draAggregate($proposal);

        expect($snapshot['narratives'])->toBe([['project_id' => $center->id, 'month' => '2026-01', 'question' => 'Q1', 'answer' => 'Yes']])
            ->and(collect($snapshot['files'])->pluck('id')->all())->toBe([$file->id])
            ->and($snapshot['files'][0]['monthly_report_id'])->toBe($validated->id);
    });

    test('a center appears from its first generated month', function () {
        $proposal = donorReportProposal();
        $early = donorReportCenter($proposal);
        $late = donorReportCenter($proposal);

        donorReportMonth($early, '2026-01', MonthlyReportStatus::Draft);
        donorReportMonth($late, '2026-03', MonthlyReportStatus::Draft);

        $missing = collect(draAggregate($proposal)['completeness']['missing']);

        expect($missing->where('project_id', $late->id)->pluck('month')->all())->toBe(['2026-03'])
            ->and($missing->where('project_id', $early->id)->pluck('month')->all())->toBe(['2026-01']);
    });

    test('the header comes from system data', function () {
        $proposal = donorReportProposal(['name' => 'Water for Idlib']);
        $goal = Result::factory()->type(ResultType::Impact)->create(['proposal_id' => $proposal->id, 'program_id' => null, 'statement' => 'Safe water for all.']);

        $header = draAggregate($proposal)['header'];

        expect($header['proposal_name'])->toBe('Water for Idlib')
            ->and($header['contract_number'])->toBe('UN-77')
            ->and($header['goal'])->toBe($goal->statement)
            ->and($header['implementer'])->toBe(config('app.name'));
    });

    test('a period without validated data can still be aggregated', function () {
        $snapshot = draAggregate(donorReportProposal());

        expect($snapshot['completeness']['has_validated_data'])->toBeFalse()
            ->and($snapshot['indicators'])->toBe([]);
    });

    test('a self-funded proposal is refused', function () {
        expect(fn () => draAggregate(donorReportProposal(internal: true)))
            ->toThrow(ValidationException::class, __('donor_reports.self_funded'));
    });
});

describe('finance', function () {
    test('finance lists received, fees and net per currency for the period', function () {
        $proposal = donorReportProposal();
        $agreementId = DonorProposal::query()->where('proposal_id', $proposal->id)->value('id');
        $tranche = fn (array $attributes) => Activity::withoutRecording(fn () => TransfersTracking::factory()->received()->create([
            'donor_proposal_id' => $agreementId,
            'scheduled_date' => '2026-02-10',
            'amount_transfered_currency' => 'USD',
            'amount_received_currency' => 'USD',
            'fees_currency' => 'USD',
            ...$attributes,
        ]));

        $tranche(['payment_amount' => 1000, 'amount_received' => 1000, 'fees' => 20, 'net_amount_received' => 980, 'receive_date' => '2026-02-12']);
        $tranche(['payment_amount' => 500, 'amount_received' => 500, 'fees' => 10, 'net_amount_received' => 490, 'receive_date' => '2026-03-01']);
        $tranche(['payment_amount' => 300, 'amount_received' => 300, 'fees' => 5, 'fees_currency' => 'USD', 'net_amount_received' => 295, 'amount_transfered_currency' => 'EUR', 'amount_received_currency' => 'EUR', 'receive_date' => '2026-03-05']);
        $tranche(['payment_amount' => 700, 'amount_received' => 700, 'fees' => 0, 'net_amount_received' => 700, 'receive_date' => '2026-05-01']);
        $tranche(['payment_amount' => 900, 'ar_status' => ArStatus::Pending, 'receive_date' => '2026-03-02']);

        $finance = draAggregate($proposal, finance: true)['finance'];

        expect($finance)->toEqual([
            ['currency' => 'EUR', 'received' => 300.0, 'net' => 295.0, 'net_missing' => 0, 'fees' => [['currency' => 'USD', 'amount' => 5.0]]],
            ['currency' => 'USD', 'received' => 1500.0, 'net' => 1470.0, 'net_missing' => 0, 'fees' => [['currency' => 'USD', 'amount' => 30.0]]],
        ]);
    });

    test('without finance there is no finance key and no money query', function () {
        $proposal = donorReportProposal();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $snapshot = draAggregate($proposal);

        expect($snapshot)->not->toHaveKey('finance')
            ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'transfers_tracking')))->toBeEmpty();
    });
});

test('the query count does not grow with centers and months', function () {
    $count = function (int $centers, int $months): int {
        $proposal = donorReportProposal();
        $program = Program::factory()->create(['donor_attribution' => DonorAttribution::Split]);
        $library = LibraryIndicator::factory()->create(['is_disaggregated' => true]);
        $ratio = LibraryIndicator::factory()->percentage()->create(['denominator_basis' => DenominatorBasis::Entered]);
        $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposal->id, 'program_id' => null]);
        $activity = Result::factory()->activity($output)->create();

        for ($c = 0; $c < $centers; $c++) {
            $center = donorReportCenter($proposal, program: $program);
            $target = donorReportTarget($proposal, $center, $library);
            $ratioTarget = donorReportTarget($proposal, $center, $ratio, '80');

            for ($m = 1; $m <= $months; $m++) {
                $month = sprintf('2026-%02d', $m);
                $share = donorReportFigures($center, $month, $library, [$target->id => ['value' => 5]])[$target->id];
                donorReportFigures($center, $month, $ratio, [$ratioTarget->id => ['value' => 50, 'numerator' => 1, 'denominator' => 2]]);
                MonthlyReportLineBreakdown::factory()->create(['monthly_report_line_id' => $share->monthly_report_line_id, 'monthly_report_line_share_id' => $share->id, 'gender' => Gender::Male, 'value' => 5, 'disabled_value' => 1]);
                MonthlyReportAnswer::factory()->create(['monthly_report_id' => $share->line->monthly_report_id]);
                MonthlyReportFile::factory()->create(['monthly_report_id' => $share->line->monthly_report_id]);
                MonthlyReportActivityEntry::factory()->create(['monthly_report_id' => $share->line->monthly_report_id, 'result_id' => $activity->id, 'status' => ActivityStatus::Ongoing]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        draAggregate($proposal, '2026-01-01', '2026-12-31', true);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    expect($count(10, 12))->toBe($count(2, 2));
});
