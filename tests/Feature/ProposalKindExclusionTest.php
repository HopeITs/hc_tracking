<?php

use App\Actions\DonorReports\ComposeDonorReport;
use App\Actions\Results\CopyResultTree;
use App\Actions\Results\SaveActivitySchedule;
use App\Actions\Results\SaveResult;
use App\Actions\Targets\AttachLibraryIndicatorToResult;
use App\Actions\Targets\SaveProposalTargetsGrid;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\DonorReportAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * An active payroll proposal (D-55b): approved by its donor, with no project.
 */
function activePayrollProposal(): Proposal
{
    return Proposal::factory()->payroll()->create([
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
    ]);
}

/**
 * The validation errors a refused call reports.
 *
 * @return array<string, list<string>>
 */
function kindRefusal(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

test('the donor report aggregator and composer refuse a payroll proposal', function () {
    $proposal = activePayrollProposal();
    $actor = userWithRole('General Manager');

    $aggregated = kindRefusal(fn () => app(DonorReportAggregator::class)->aggregate($proposal, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'), false));
    $composed = kindRefusal(fn () => app(ComposeDonorReport::class)->eligibleProposal($proposal->id, $actor));

    expect($aggregated['proposalId'])->toBe([__('donor_reports.payroll_has_final_report_only')])
        ->and($composed['proposalId'])->toBe([__('donor_reports.payroll_has_final_report_only')]);
});

test('a payroll proposal takes no logframe, target or activity schedule', function (Closure $call, string $field, string $message) {
    $proposal = activePayrollProposal();
    // MEAL writes the logframe and targets; copying a tree also needs to see the proposal.
    $meal = mealManager();
    $meal->givePermissionTo('proposals.view');
    $this->actingAs($meal);

    $errors = kindRefusal(fn () => $call($proposal));

    expect($errors[$field])->toBe([__($message)])
        ->and(Result::query()->where('proposal_id', $proposal->id)->count())->toBe(0)
        ->and(Indicator::query()->count())->toBe(0);
})->with([
    'a result' => [fn (Proposal $proposal) => app(SaveResult::class)->handle(null, ['statement' => 'Volunteers are paid on time.', 'type' => 'impact', 'checkout_period' => 'monthly'], $proposal), 'proposal', 'results_framework.payroll_has_no_framework'],
    'a copied framework' => [fn (Proposal $proposal) => app(CopyResultTree::class)->handle(Result::factory()->create(['proposal_id' => null]), $proposal), 'proposal', 'results_framework.payroll_has_no_framework'],
    'an activity schedule' => [fn (Proposal $proposal) => app(SaveActivitySchedule::class)->handle($proposal, 1, null, ['months' => ['2026-02'], 'weeks' => [], 'expected_version' => 'v1']), 'proposal', 'results_framework.payroll_has_no_framework'],
    'a library indicator' => [fn (Proposal $proposal) => app(AttachLibraryIndicatorToResult::class)->handle($proposal, 1, 1), 'proposal', 'targets.payroll_has_no_targets'],
    'a targets grid' => [fn (Proposal $proposal) => app(SaveProposalTargetsGrid::class)->handle($proposal, []), 'proposal', 'targets.payroll_has_no_targets'],
]);

test('the reporting matrix and the targets panel read the same with a payroll proposal present', function () {
    $actor = userWithRole('General Manager', insertHrEmployee());

    Activity::withoutRecording(function (): void {
        $project = Project::factory()->create(['name' => 'Late Wells']);
        MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-09-01', 'status' => MonthlyReportStatus::Draft]);
    });

    $matrixBefore = Livewire::actingAs($actor)->test('desk.panels.matrix', ['size' => 'XL'])->instance()->rows()->modelKeys();
    $targetsBefore = Livewire::actingAs($actor)->test('desk.panels.targets', ['size' => 'XL'])->instance()->rows()->count();

    activePayrollProposal();

    expect(Livewire::actingAs($actor)->test('desk.panels.matrix', ['size' => 'XL'])->instance()->rows()->modelKeys())->toBe($matrixBefore)
        ->and(Livewire::actingAs($actor)->test('desk.panels.targets', ['size' => 'XL'])->instance()->rows()->count())->toBe($targetsBefore)
        ->and(Project::query()->count())->toBe(1);
});
