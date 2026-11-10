<?php

use App\Enums\MilestoneStatus;
use App\Models\AnnualPlan;
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
use App\Support\StrategyRollup;
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

/**
 * The rendered block of one current strategy.
 */
function currentStrategyBlock(string $html, Strategy $strategy): string
{
    return str($html)->after('data-test="current-strategy-'.$strategy->id.'"')->before('</article>')->toString();
}

/**
 * Give the program a plan for 2026, so "no 2026 plan" stays out of the attention list.
 */
function planProgramFor2026(Program $program): void
{
    PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]))->create();
}

test('each current strategy adds up its targets on pace, high risks and late work', function () {
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

    $block = currentStrategyBlock(Livewire::actingAs($user)->test('pages::strategies.index')->html(), $this->strategy);

    expect($block)
        ->toContain('data-verdict="off_track"')
        ->toContain(trans_choice('strategies.verdict_on_pace', 2, ['on' => '<bdi>1</bdi>', 'total' => '<bdi>2</bdi>']))
        ->toContain('class="text-status-critical" data-test="strategy-high-risks">1</dd>')
        ->toContain('class="text-status-critical" data-test="strategy-late-reports">1</dd>')
        ->toContain(trans_choice('strategies.attention_high_risks', 1, ['count' => '<bdi>1</bdi>']))
        ->toContain(trans_choice('strategies.attention_missed_milestones', 1, ['count' => '<bdi>1</bdi>']))
        ->toContain(e(route('risks.index', ['strategy' => $this->strategy->id, 'high' => 1])))
        ->toContain(e(route('milestones.index', ['strategy' => $this->strategy->id, 'status' => 'missed', 'view' => 'list'])));
});

/*
| Page review, 6 Nov 2026: "On track · 39 of 39 targets are on pace" in green
| while 22 more targets of the strategy had no figures at all.
*/

test('targets with no figures yet are counted in the sentence and keep the verdict from green', function () {
    strategyIndicator($this->program, 100);
    strategyIndicator($this->program, 100);
    Indicator::factory()->create([
        'result_id' => Result::factory()->create(['program_id' => $this->program->id])->id,
        'baseline' => 0,
        'target' => 100,
    ]);

    $block = currentStrategyBlock(Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::strategies.index')->html(), $this->strategy);

    expect($block)
        ->toContain('class="dk-verdict dk-warn"')
        ->toContain('data-verdict="incomplete"')
        ->toContain('<strong>'.__('strategies.verdict_incomplete').'</strong>')
        ->toContain('<bdi>2</bdi> of <bdi>3</bdi> targets are on pace.')
        ->toContain(trans_choice('strategies.verdict_unrated', 1, ['count' => '<bdi>1</bdi>']));
});

test('the verdict sentence names the verdict in its tone', function (array $readings, string $key, string $tone, string $sentence) {
    foreach ($readings as $reading) {
        strategyIndicator($this->program, $reading);
    }

    $block = currentStrategyBlock(Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::strategies.index')->html(), $this->strategy);

    expect($block)
        ->toContain('class="dk-verdict '.$tone.'"')
        ->toContain('data-verdict="'.$key.'"')
        ->toContain('<strong>'.__('strategies.verdict_'.$key).'</strong>')
        ->toContain($sentence)
        // The current year on the year line takes the verdict's tone.
        ->toContain('class="dk-now '.$tone.'" aria-current="step"');
})->with([
    'all on pace' => [[100, 100], 'on_track', 'dk-good', 'of <bdi>2</bdi> targets are on pace.'],
    '4 of 5 on pace' => [[100, 100, 100, 100, 0], 'mostly_on_track', 'dk-warn', '<bdi>1</bdi> is falling behind.'],
    'half on pace' => [[100, 0], 'off_track', 'dk-bad', '<bdi>1</bdi> is falling behind.'],
]);

test('with no rated targets the verdict is a neutral sentence', function () {
    $block = currentStrategyBlock(Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::strategies.index')->html(), $this->strategy);

    expect($block)
        ->toContain('class="dk-verdict dk-mute"')
        ->toContain(__('strategies.verdict_none'));
});

test('the attention list links a late report to the late reports register, and says when nothing needs attention', function () {
    planProgramFor2026($this->program);
    $user = User::factory()->role('Team Member')->create();

    $calm = currentStrategyBlock(Livewire::actingAs($user)->test('pages::strategies.index')->html(), $this->strategy);

    expect($calm)->toContain(__('strategies.nothing_needs_attention'));

    Report::factory()->create([
        'plan_indicator_id' => PlanIndicator::factory()->create(['project_id' => $this->project->id])->id,
        'planned_date' => '2026-05-31',
    ]);

    $late = currentStrategyBlock(Livewire::actingAs($user)->test('pages::strategies.index')->html(), $this->strategy);

    expect($late)
        ->toContain(trans_choice('strategies.attention_late_reports', 1, ['count' => '<bdi>1</bdi>']))
        ->toContain('href="'.e(route('reports.index', ['strategy' => $this->strategy->id, 'overdue' => 1])).'" wire:navigate.hover class="dk-attn-a">'.__('strategies.see_late_reports'))
        ->not->toContain(__('strategies.nothing_needs_attention'));
});

test('the attention list counts the programs with no plan for this year', function () {
    Program::factory()->create(['strategy_id' => $this->strategy->id]);

    $block = currentStrategyBlock(Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::strategies.index')->html(), $this->strategy);

    expect($block)
        ->toContain(trans_choice('strategies.attention_no_plan', 2, ['count' => '<bdi>2</bdi>', 'year' => '<bdi>2026</bdi>']))
        ->toContain(e(route('programs.index', ['strategy' => $this->strategy->id, 'gap' => 'no_plan'])));
});

test('funding shows the agreed amount and the share funded, only to roles that may see money', function () {
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
        ->assertSeeHtml('<bdi>$120K</bdi>')
        ->assertSeeText(__('strategies.percent_funded', ['percent' => 60]));

    Livewire::actingAs($teamMember)
        ->test('pages::strategies.index')
        ->assertDontSeeHtml('data-test="strategy-funding"')
        ->assertDontSeeText(__('strategies.col_funding'))
        ->assertDontSeeText('$120K');
});

test('funding in several currencies shows every currency inline, with no "+1"', function () {
    $dollars = Proposal::factory()->create(['requested_budget' => 500000, 'requested_currency' => 'USD']);
    $euros = Proposal::factory()->create(['requested_budget' => null]);
    $this->project->proposals()->attach([$dollars->id, $euros->id]);
    DonorProposal::factory()->create(['proposal_id' => $dollars->id, 'budget' => 420000, 'currency' => 'USD']);
    DonorProposal::factory()->create(['proposal_id' => $euros->id, 'budget' => 3960000, 'currency' => 'EUR']);

    $block = currentStrategyBlock(Livewire::actingAs(User::factory()->role('Financial')->create())->test('pages::strategies.index')->html(), $this->strategy);

    expect($block)
        ->toContain('<bdi>$420K</bdi>')
        ->toContain('<bdi>€3.96M</bdi>')
        ->toContain('<span class="dk-sfig-sep" aria-hidden="true">·</span>')
        ->toContain(__('strategies.percent_funded', ['percent' => 84]))
        ->not->toContain('+1');
});

test('a past strategy with no programs offers a labelled "Link programs" button, and no icon-only buttons', function () {
    $empty = Strategy::factory()->create(['name' => 'Empty Strategy', 'start_year' => 2016, 'end_year' => 2020]);

    $html = Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::strategies.index')
        ->assertSeeText(__('strategies.no_programs_linked'))
        ->assertSeeHtml('wire:click="openProgramsModal('.$empty->id.')"')
        ->assertSeeHtml('data-test="strategy-link-programs">'.__('strategies.link_programs').'</button>')
        ->assertDontSeeHtml('data-test="manage-strategy-programs-button"')
        ->assertDontSeeHtml('data-test="edit-strategy-button"')
        ->html();

    // Every button and link in the row carries words of its own, not an icon alone.
    $row = str($html)->after('data-test="past-strategy-'.$empty->id.'"')->before('</li>')->toString();
    preg_match_all('/<(button|a)\b[^>]*>(.*?)<\/\1>/s', $row, $controls);
    $labels = array_map(fn (string $inner): string => trim(strip_tags($inner)), $controls[2]);
    expect($labels)->not->toBeEmpty()->not->toContain('');

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::strategies.index')
        ->assertSeeText(__('strategies.no_programs_linked'))
        ->assertDontSeeHtml('data-test="strategy-link-programs"');
});

test('a past strategy with pillars or programs offers no delete; one with neither does', function () {
    $withPillars = Strategy::factory()->create(['name' => 'Pillared Strategy', 'start_year' => 2016, 'end_year' => 2020]);
    StrategicPillar::factory()->create(['strategy_id' => $withPillars->id]);
    $withPrograms = Strategy::factory()->create(['name' => 'Programmed Strategy', 'start_year' => 2011, 'end_year' => 2015]);
    Program::factory()->create(['strategy_id' => $withPrograms->id]);
    $bare = Strategy::factory()->create(['name' => 'Bare Strategy', 'start_year' => 2006, 'end_year' => 2010]);

    $html = Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.index')
        ->html();

    expect(substr_count($html, 'data-test="delete-strategy-button"'))->toBe(1)
        ->and($html)->toContain('wire:click="confirmDelete('.$bare->id.')"')
        ->and($html)->not->toContain('wire:click="confirmDelete('.$withPillars->id.')"')
        ->and($html)->not->toContain('wire:click="confirmDelete('.$withPrograms->id.')"')
        ->and($html)->toContain(trans_choice('strategies.count_program', 1, ['count' => 1]).' · '.__('strategies.ended_on', ['date' => 'Dec 2015']));
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

test('the rollup counts rated targets per program', function () {
    $other = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    $unrated = Program::factory()->create(['strategy_id' => $this->strategy->id]);

    strategyIndicator($this->program, 100);
    strategyIndicator($this->program, 0);
    strategyIndicator($other, 100);

    $results = StrategyRollup::for(User::factory()->role('Team Member')->create(), [$this->strategy->id])[$this->strategy->id]['results'];

    expect($results['on'])->toBe(2)
        ->and($results['total'])->toBe(3)
        ->and($results['by_program'])->toBe([
            $this->program->id => ['on' => 1, 'total' => 2],
            $other->id => ['on' => 1, 'total' => 1],
        ])
        ->and($results['by_program'])->not->toHaveKey($unrated->id);
});

test('plan readiness counts programs planned for the strategy year, ignoring empty shells', function () {
    $shell = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    $bare = Program::factory()->create(['strategy_id' => $this->strategy->id]);
    $emptyStrategy = Strategy::factory()->create();

    $plan = AnnualPlan::factory()->create(['program_id' => $this->program->id, 'year' => 2026]);
    PlanIndicator::factory()->forAnnualPlan($plan)->create();
    AnnualPlan::factory()->create(['program_id' => $shell->id, 'year' => 2026]);
    $otherYear = AnnualPlan::factory()->create(['program_id' => $bare->id, 'year' => 2027]);
    PlanIndicator::factory()->forAnnualPlan($otherYear)->create();

    $readiness = StrategyRollup::planReadiness(User::factory()->role('Team Member')->create(), [
        $this->strategy->id => 2026,
        $emptyStrategy->id => 2026,
    ]);

    expect($readiness)->toBe([
        $this->strategy->id => ['planned' => 1, 'programs' => 3, 'missing' => 2],
        $emptyStrategy->id => ['planned' => 0, 'programs' => 0, 'missing' => 0],
    ]);
});

test('plan readiness is hidden from a viewer who may not see annual plans', function () {
    expect(StrategyRollup::planReadiness(User::factory()->create(), [$this->strategy->id => 2026]))->toBeNull();
});
