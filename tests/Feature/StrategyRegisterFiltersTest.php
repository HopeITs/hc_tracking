<?php

use App\Enums\MilestoneStatus;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');

    $this->strategy = Strategy::factory()->create(['name' => 'Country Strategy 2026-2030']);
    $this->ours = Project::factory()->create(['name' => 'Ours Project', 'program_id' => Program::factory()->create(['strategy_id' => $this->strategy->id])->id]);
    $this->theirs = Project::factory()->create(['name' => 'Theirs Project']);
    $this->user = User::factory()->role('Super Admin')->create();
});

/**
 * A register opened from a strategy figure.
 *
 * @param  array<string, mixed>  $query
 */
function openedFromStrategy(string $page, array $query = []): Testable
{
    return Livewire::actingAs(test()->user)
        ->withQueryParams(['strategy' => (string) test()->strategy->id, ...$query])
        ->test($page);
}

test('the risks register keeps only the strategy\'s high risks and shows a removable chip', function () {
    Risk::factory()->rated(3, 2)->create(['name' => 'Ours high', 'project_id' => $this->ours->id]);
    Risk::factory()->rated(1, 1)->create(['name' => 'Ours low', 'project_id' => $this->ours->id]);
    Risk::factory()->rated(3, 3)->create(['name' => 'Theirs high', 'project_id' => $this->theirs->id]);

    openedFromStrategy('pages::risks.index', ['high' => '1'])
        ->assertSeeText('Ours high')
        ->assertDontSeeText('Ours low')
        ->assertDontSeeText('Theirs high')
        ->assertSeeText(__('strategies.strategy_filter', ['name' => 'Country Strategy 2026-2030']))
        ->call('clearStrategyFilter')
        ->assertSet('strategy', '')
        ->assertSeeText('Theirs high');
});

test('the indicators register keeps only indicators under the strategy\'s programs', function () {
    $ourResult = Result::factory()->create(['program_id' => $this->ours->program_id]);
    Indicator::factory()->create(['name' => 'Ours indicator', 'result_id' => $ourResult->id]);
    Indicator::factory()->create(['name' => 'Theirs indicator', 'result_id' => Result::factory()->create(['program_id' => $this->theirs->program_id])->id]);

    openedFromStrategy('pages::indicators.index')
        ->assertSeeText('Ours indicator')
        ->assertDontSeeText('Theirs indicator');
});

test('the milestones register keeps only the strategy\'s missed milestones', function () {
    Milestone::factory()->create(['name' => 'Ours missed', 'project_id' => $this->ours->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2026-05-01', 'planned_end_date' => '2026-06-01']);
    Milestone::factory()->create(['name' => 'Ours upcoming', 'project_id' => $this->ours->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2026-07-01', 'planned_end_date' => '2026-08-01']);
    Milestone::factory()->create(['name' => 'Theirs missed', 'project_id' => $this->theirs->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2026-05-01', 'planned_end_date' => '2026-06-01']);

    openedFromStrategy('pages::milestones.index', ['status' => 'missed', 'view' => 'list'])
        ->assertSet('status', 'missed')
        ->assertSeeText('Ours missed')
        ->assertDontSeeText('Ours upcoming')
        ->assertDontSeeText('Theirs missed');
});

test('the reports register keeps only the strategy\'s overdue reports', function () {
    $ours = Report::factory()->create(['plan_indicator_id' => PlanIndicator::factory()->create(['project_id' => $this->ours->id])->id, 'planned_date' => '2026-05-31', 'narrative_report_notes' => 'Ours late']);
    Report::factory()->create(['plan_indicator_id' => PlanIndicator::factory()->create(['project_id' => $this->theirs->id])->id, 'planned_date' => '2026-05-31', 'narrative_report_notes' => 'Theirs late']);

    $reports = openedFromStrategy('pages::reports.index', ['overdue' => '1'])->instance()->reports;

    expect($reports->pluck('id')->all())->toBe([$ours->id]);
});

test('the funding agreements register keeps only agreements funding the strategy\'s projects', function () {
    $ourProposal = Proposal::factory()->create();
    $this->ours->proposals()->attach($ourProposal->id);
    $ours = DonorProposal::factory()->create(['proposal_id' => $ourProposal->id]);
    $theirProposal = Proposal::factory()->create();
    $this->theirs->proposals()->attach($theirProposal->id);
    DonorProposal::factory()->create(['proposal_id' => $theirProposal->id]);

    $user = User::factory()->role('Financial')->create();

    $rows = Livewire::actingAs($user)
        ->withQueryParams(['strategy' => (string) $this->strategy->id])
        ->test('pages::funding-agreements.index')
        ->assertSeeText(__('strategies.strategy_filter', ['name' => 'Country Strategy 2026-2030']))
        ->instance()->rows;

    expect($rows->pluck('id')->all())->toBe([$ours->id]);
});

test('an unknown strategy id narrows nothing and shows no chip', function () {
    Risk::factory()->create(['name' => 'Theirs risk', 'project_id' => $this->theirs->id]);

    Livewire::actingAs($this->user)
        ->withQueryParams(['strategy' => '999999'])
        ->test('pages::risks.index')
        ->assertSeeText('Theirs risk')
        ->assertDontSeeHtml('data-strategy-filter-chip');
});
