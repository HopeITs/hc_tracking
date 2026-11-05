<?php

use App\Enums\MilestoneStatus;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Risk;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');
    $this->strategy = Strategy::factory()->create(['name' => 'Country Strategy 2026-2030', 'start_year' => 2026, 'end_year' => 2030]);
    $this->program = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    $this->project = Project::factory()->create(['program_id' => $this->program->id]);
});

/**
 * An indicator under the strategy's program, read at :reading of 100.
 */
function strategyIndicator(Program $program, float $reading): Indicator
{
    $indicator = Indicator::factory()->create([
        'result_id' => Result::factory()->create(['program_id' => $program->id])->id,
        'baseline' => 0,
        'target' => 100,
    ]);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-06-01', 'value' => $reading]);

    return $indicator;
}

test('each strategy row adds up its targets on pace, high risks and late work', function () {
    strategyIndicator($this->program, 100);
    strategyIndicator($this->program, 0);
    Risk::factory()->rated(3, 3)->create(['project_id' => $this->project->id]);
    Risk::factory()->rated(1, 2)->create(['project_id' => $this->project->id]);
    Milestone::factory()->create(['project_id' => $this->project->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2026-05-01', 'planned_end_date' => '2026-06-01']);
    Report::factory()->create([
        'plan_indicator_id' => PlanIndicator::factory()->create(['project_id' => $this->project->id])->id,
        'planned_date' => '2026-05-31',
    ]);

    // Another strategy's work never counts here.
    Risk::factory()->rated(3, 3)->create();

    $user = User::factory()->role('Team Member')->create();

    Livewire::actingAs($user)
        ->test('pages::strategies.index')
        ->assertSeeHtml(e(route('indicators.index', ['strategy' => $this->strategy->id])))
        ->assertSeeText('1/2')
        ->assertSeeHtml(e(route('risks.index', ['strategy' => $this->strategy->id, 'high' => 1])).'" wire:navigate class="font-semibold tabular-nums text-secondary-dark underline-offset-2 hover:underline dark:text-ink text-status-critical!" data-test="strategy-risks-link">1</a>')
        ->assertSeeText(trans_choice('strategies.count_late_milestones', 1, ['count' => 1]))
        ->assertSeeText(trans_choice('strategies.count_late_reports', 1, ['count' => 1]))
        ->assertSeeHtml(e(route('milestones.index', ['strategy' => $this->strategy->id, 'status' => 'missed', 'view' => 'list'])))
        ->assertSeeHtml(e(route('reports.index', ['strategy' => $this->strategy->id, 'overdue' => 1])));
});

test('funding shows agreed against requested per currency, only to roles that may see money', function () {
    $proposal = Proposal::factory()->create(['requested_budget' => 200000, 'requested_currency' => 'USD']);
    $this->project->proposals()->attach($proposal->id);
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => 120000, 'currency' => 'USD']);

    $financial = User::factory()->role('Financial')->create();
    $teamMember = User::factory()->role('Team Member')->create();

    expect($financial->can('funding-agreements.view'))->toBeTrue()
        ->and($teamMember->can('funding-agreements.view'))->toBeFalse();

    Livewire::actingAs($financial)
        ->test('pages::strategies.index')
        ->assertSeeText(__('strategies.col_funding'))
        ->assertSeeText('60%')
        ->assertSeeText(__('strategies.funding_line_requested', ['agreed' => '$120,000', 'requested' => '$200,000', 'percent' => 60]))
        ->assertSeeHtml(e(route('funding-agreements.index', ['strategy' => $this->strategy->id])));

    Livewire::actingAs($teamMember)
        ->test('pages::strategies.index')
        ->assertDontSeeText(__('strategies.col_funding'))
        ->assertDontSeeText('$120,000');
});

test('a strategy with no programs shows one quiet line instead of empty figures', function () {
    $empty = Strategy::factory()->create(['name' => 'Empty Strategy', 'start_year' => 2031, 'end_year' => 2035]);

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::strategies.index')
        ->assertSeeHtml('data-test="strategy-no-programs"')
        ->assertSeeText(__('strategies.no_programs_yet_short'))
        ->assertSeeHtml('wire:click="openProgramsModal('.$empty->id.')"')
        ->assertSeeHtml(e(route('programs.index', ['strategy' => $this->strategy->id])));

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::strategies.index')
        ->assertSeeText(__('strategies.no_programs_yet_short'))
        ->assertDontSeeHtml('data-test="strategy-link-programs"');
});

test('a strategy with pillars shows a lock with the reason instead of the delete button', function () {
    $withPillars = Strategy::factory()->create(['name' => 'Pillared Strategy', 'start_year' => 2031, 'end_year' => 2035]);
    StrategicPillar::factory()->create(['strategy_id' => $withPillars->id]);

    $component = Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.index');

    expect(substr_count($component->html(), 'data-test="delete-strategy-locked"'))->toBe(2)
        ->and($component->html())->toContain(e(__('strategies.strategy_has_pillars')))
        ->and($component->html())->not->toContain('data-test="delete-strategy-button"');
});

test('the form warns about overlapping years but still saves', function () {
    $manager = User::factory()->role('Super Admin')->create();

    Livewire::actingAs($manager)
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->set('name', 'Regional Strategy')
        ->set('startYear', '2030')
        ->set('endYear', '2032')
        ->assertSeeHtml('data-test="strategy-overlap-warning"')
        ->assertSeeText("\u{2068}Country Strategy 2026-2030\u{2069} \u{2066}(2026–2030)\u{2069}")
        ->call('saveStrategy')
        ->assertHasNoErrors();

    expect(Strategy::query()->where('name', 'Regional Strategy')->exists())->toBeTrue();
});

test('editing a strategy never warns that it overlaps itself', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.index')
        ->call('openEditModal', $this->strategy->id)
        ->assertDontSeeHtml('data-test="strategy-overlap-warning"');
});

test('missing years get the same plain instructions as a missing name', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.index')
        ->call('openCreateModal')
        ->call('saveStrategy')
        ->assertHasErrors([
            'name' => __('strategies.enter_a_strategy_name'),
            'startYear' => __('strategies.enter_a_start_year'),
            'endYear' => __('strategies.enter_an_end_year'),
        ]);
});
