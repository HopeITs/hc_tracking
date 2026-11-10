<?php

use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-06-15 09:00:00');
});

/**
 * A target of the program, rated this year by one reading of :reading out of 100.
 */
function strategyPageTarget(Program $program, float $reading): void
{
    $indicator = Indicator::factory()->create([
        'result_id' => Result::factory()->create(['program_id' => $program->id])->id,
        'baseline' => 0,
        'target' => 100,
    ]);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-06-01', 'value' => $reading]);
}

/**
 * Give the program an annual plan for the year with one plan indicator.
 */
function strategyPagePlan(Program $program, int $year): void
{
    PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => $year]))->create();
}

/**
 * The rendered row of one program on the strategy page.
 */
function strategyPageRow(string $html, Program $program): string
{
    return str($html)->after('data-test="strategy-program-'.$program->id.'"')->before('</li>')->toString();
}

test('guests are redirected from strategy detail', function () {
    $strategy = Strategy::factory()->create();

    $this->get(route('strategies.show', $strategy))->assertRedirect(route('login'));
});

test('the strategy page is its own full page with a back link and a breadcrumb to the list', function () {
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertDontSee('data-desk-record-host', false)
        ->assertDontSee('data-desk-register-view', false)
        ->assertSee('class="dk-story dk-strat"', false)
        ->assertSee('class="dk-back" href="'.route('strategies.index').'" wire:navigate', false)
        ->assertSee('<nav class="dk-crumb" aria-label="'.__('ui.breadcrumb').'">', false)
        ->assertSee('<a href="'.route('strategies.index').'" wire:navigate.hover>'.__('common.strategies').'</a>', false)
        ->assertSee('<span aria-current="page">Country Strategy</span>', false);
});

test('strategy detail shows the strategy, its year and span, and its programs with their manager', function () {
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $strategy->id, 'manager_id' => $manager]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSeeText('Country Strategy')
        ->assertSeeText('Year 1 of 5 · Jan 2026 – Dec 2030')
        ->assertSeeText('Water Security')
        ->assertSeeText('Layla Haddad');
});

test('the status pill and the date span follow the strategy years', function (int $start, int $end, string $pill, string $label, string $span) {
    $strategy = Strategy::factory()->create(['start_year' => $start, 'end_year' => $end]);

    $html = $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->getContent();

    expect($html)
        ->toContain('class="'.$pill.'" data-test="strategy-status">'.$label.'</span>')
        ->toContain($span)
        ->not->toContain('bg-primary text-white');
})->with([
    'active' => [2026, 2028, 'dk-pill dk-good', 'Active', 'Year <bdi>1</bdi> of <bdi>3</bdi> · <bdi>Jan 2026</bdi> – <bdi>Dec 2028</bdi>'],
    'upcoming' => [2027, 2029, 'dk-pill dk-act', 'Upcoming', '<bdi>Jan 2027</bdi> – <bdi>Dec 2029</bdi>'],
    'completed' => [2020, 2024, 'dk-pill dk-mute', 'Completed', '<bdi>Jan 2020</bdi> – <bdi>Dec 2024</bdi>'],
]);

test('strategy detail does not show another strategy programs', function () {
    $subject = Strategy::factory()->create(['name' => 'Country Strategy']);
    $other = Strategy::factory()->create(['name' => 'Regional Strategy']);
    Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $subject->id]);
    Program::factory()->create(['name' => 'Safe Shelter', 'strategy_id' => $other->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $subject))
        ->assertSeeText('Water Security')
        ->assertDontSeeText('Safe Shelter');
});

test('a strategy with no programs says so and offers to link programs', function () {
    $strategy = Strategy::factory()->create(['name' => 'Empty Strategy']);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSeeText('Empty Strategy')
        ->assertSeeText(__('strategies.no_programs_linked_here'))
        ->assertSee('wire:click="openProgramsModal('.$strategy->id.')" data-test="strategy-empty-link-programs">'.__('strategies.link_programs').'</button>', false);
});

test('the plan figure counts the programs whose plan for this year carries a plan indicator', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2029]);
    $planned = Program::factory()->create(['strategy_id' => $strategy->id]);
    $emptyShell = Program::factory()->create(['strategy_id' => $strategy->id]);
    $nextYearOnly = Program::factory()->create(['strategy_id' => $strategy->id]);
    strategyPagePlan($planned, 2026);
    AnnualPlan::factory()->create(['program_id' => $emptyShell->id, 'year' => 2026]);
    strategyPagePlan($nextYearOnly, 2027);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('data-test="strategy-plans-link"><bdi>1/3</bdi></a></dd>', false)
        ->assertSeeText(__('strategies.fig_have_plan', ['year' => 2026]))
        ->assertSee(e(route('programs.index', ['strategy' => $strategy->id, 'gap' => 'no_plan'])), false);
});

test('the plan figure is left out while the strategy is not running this year', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2027, 'end_year' => 2029]);
    Program::factory()->create(['strategy_id' => $strategy->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertDontSee('data-test="strategy-plans-link"', false)
        ->assertDontSeeText(__('strategies.state_no_plan', ['year' => 2026]));
});

test('each program row says in words where the program stands', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    $unplanned = Program::factory()->create(['strategy_id' => $strategy->id]);
    $behind = Program::factory()->create(['strategy_id' => $strategy->id]);
    $onPace = Program::factory()->create(['strategy_id' => $strategy->id]);
    $unrated = Program::factory()->create(['strategy_id' => $strategy->id]);
    foreach ([$behind, $onPace, $unrated] as $program) {
        strategyPagePlan($program, 2026);
    }
    strategyPageTarget($behind, 100);
    strategyPageTarget($behind, 0);
    strategyPageTarget($behind, 0);
    strategyPageTarget($onPace, 100);
    strategyPageTarget($onPace, 100);

    $html = $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->getContent();

    expect(strategyPageRow($html, $unplanned))->toContain('class="dk-sprow-state dk-warn" data-program-state="no_plan">No 2026 plan</span>');
    expect(strategyPageRow($html, $behind))->toContain('class="dk-sprow-state dk-bad" data-program-state="behind">'.__('strategies.state_behind').'</span>');
    expect(strategyPageRow($html, $onPace))->toContain('class="dk-sprow-state dk-good" data-program-state="on_pace">'.__('strategies.state_on_pace').'</span>');
    expect(strategyPageRow($html, $unrated))->toContain('class="dk-sprow-state dk-mute" data-program-state="no_progress">'.__('strategies.state_no_progress').'</span>');
});

test('the attention list names the programs falling behind and links to the targets off pace', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    $alpha = Program::factory()->create(['name' => 'Alpha Program', 'strategy_id' => $strategy->id]);
    $beta = Program::factory()->create(['name' => 'Beta Program', 'strategy_id' => $strategy->id]);
    $steady = Program::factory()->create(['name' => 'Steady Program', 'strategy_id' => $strategy->id]);
    foreach ([$alpha, $beta, $steady] as $program) {
        strategyPagePlan($program, 2026);
    }
    strategyPageTarget($alpha, 0);
    strategyPageTarget($beta, 0);
    strategyPageTarget($steady, 100);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('<bdi>2</bdi> programs are falling behind: <bdi>Alpha Program</bdi> and <bdi>Beta Program</bdi>.', false)
        ->assertSee(e(route('indicators.index', ['strategy' => $strategy->id, 'focus' => 'offtrack'])), false)
        ->assertSeeText(__('strategies.see_targets'))
        ->assertSeeText(__('strategies.needs_attention_count', ['count' => 1]));
});

test('the attention list says so when nothing needs attention', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    strategyPagePlan(Program::factory()->create(['strategy_id' => $strategy->id]), 2026);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSeeText(__('strategies.nothing_needs_attention'))
        ->assertDontSeeText(__('strategies.see_targets'));
});

test('the verdict sentence on the strategy page takes the tone of the share on pace', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    $program = Program::factory()->create(['strategy_id' => $strategy->id]);
    foreach ([100, 100, 100, 100, 0] as $reading) {
        strategyPageTarget($program, $reading);
    }

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('class="dk-verdict dk-warn"', false)
        ->assertSee('data-verdict="mostly_on_track"', false)
        ->assertSee('<strong>'.__('strategies.verdict_mostly_on_track').'</strong>', false)
        ->assertSee('class="dk-now dk-warn" aria-current="step"', false);
});

test('the key figures are big typographic numbers, not stat tiles', function () {
    $strategy = Strategy::factory()->create();
    Program::factory()->create(['strategy_id' => $strategy->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('<dl class="dk-sfigs" data-test="strategy-figures">', false)
        ->assertSeeTextInOrder([__('strategies.fig_pillars'), __('common.programs'), __('common.projects')])
        ->assertDontSee('shrink-0 items-center justify-center rounded-xl', false)
        ->assertDontSeeText(__('strategies.plan_coverage'));
});

test('program rows under a pillar are listed by name with their project count', function () {
    $strategy = Strategy::factory()->create();
    $pillar = StrategicPillar::factory()->for($strategy)->create();
    $zebra = Program::factory()->create(['name' => 'Zebra Program', 'strategy_id' => $strategy->id, 'strategic_pillar_id' => $pillar->id]);
    Program::factory()->create(['name' => 'Alpha Program', 'strategy_id' => $strategy->id, 'strategic_pillar_id' => $pillar->id]);
    Project::factory()->count(3)->create(['program_id' => $zebra->id]);

    $response = $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy));

    $response->assertSeeTextInOrder(['Alpha Program', 'Zebra Program']);
    expect(strategyPageRow($response->getContent(), $zebra))->toContain(trans_choice('common.count_project', 3, ['count' => 3]));
});

test('every program is listed and every figure counts the whole strategy, past 15 programs', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2026]);
    $programs = Program::factory()->count(17)->create(['strategy_id' => $strategy->id]);
    Project::factory()->count(2)->create(['program_id' => $programs[16]->id]);

    $html = $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', [$strategy, 'programsPage' => 2]))
        ->getContent();

    expect($html)
        ->toContain('data-test="strategy-program-'.$programs[0]->id.'"')
        ->toContain('data-test="strategy-program-'.$programs[16]->id.'"')
        ->toContain('data-test="strategy-programs-link">17</a>')
        ->toContain('data-test="strategy-projects-link">2</a>')
        ->toContain('data-test="strategy-plans-link"><bdi>0/17</bdi></a>');
});

test('the strategy page has no timeline, only the year line', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    $program = Program::factory()->create(['strategy_id' => $strategy->id]);
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('data-year-line', false)
        // The removed x-ui.timeline rendered the span as a formatted date range.
        ->assertDontSeeText('Jan 1, 2026');
});

test('the programs and projects figures add up every program of the strategy', function () {
    $strategy = Strategy::factory()->create();
    $first = Program::factory()->create(['strategy_id' => $strategy->id]);
    $second = Program::factory()->create(['strategy_id' => $strategy->id]);
    Project::factory()->count(2)->create(['program_id' => $first->id]);
    Project::factory()->count(1)->create(['program_id' => $second->id]);
    Project::factory()->create();

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('data-test="strategy-programs-link">2</a>', false)
        ->assertSee('data-test="strategy-projects-link">3</a>', false);
});

test('the programs and projects figures link to their own registers narrowed to the strategy', function () {
    $strategy = Strategy::factory()->create();
    Program::factory()->create(['strategy_id' => $strategy->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('href="'.e(route('programs.index', ['strategy' => $strategy->id])).'" wire:navigate.hover data-test="strategy-programs-link"', false)
        ->assertSee('href="'.e(route('projects.index', ['strategy' => $strategy->id])).'" wire:navigate.hover data-test="strategy-projects-link"', false);
});

test('programs under no pillar get a notice that jumps to their group', function () {
    $strategy = Strategy::factory()->create();
    $pillar = StrategicPillar::factory()->for($strategy)->create();
    Program::factory()->count(2)->create(['strategy_id' => $strategy->id]);
    Program::factory()->create(['strategy_id' => $strategy->id, 'strategic_pillar_id' => $pillar->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSeeText("2 programs aren't under any pillar yet.", false)
        ->assertSee('href="#unassigned-programs"', false)
        ->assertSee('id="unassigned-programs"', false);
});

test('no notice and no group when every program sits under a pillar', function () {
    $strategy = Strategy::factory()->create();
    $pillar = StrategicPillar::factory()->for($strategy)->create();
    Program::factory()->create(['strategy_id' => $strategy->id, 'strategic_pillar_id' => $pillar->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertDontSee('data-test="unassigned-notice"', false)
        ->assertDontSee('id="unassigned-programs"', false);
});

test('a pillar shows its first three programs and folds the rest behind a labelled toggle', function () {
    $strategy = Strategy::factory()->create();
    $pillar = StrategicPillar::factory()->for($strategy)->create();
    $programs = Program::factory()->count(5)->sequence(fn ($sequence) => ['name' => 'Program '.($sequence->index + 1)])->create(['strategy_id' => $strategy->id, 'strategic_pillar_id' => $pillar->id]);

    $html = $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->getContent();

    expect(strategyPageRow($html, $programs[2]))->not->toContain('x-show="more"');
    expect(strategyPageRow($html, $programs[3]))->toContain('x-show="more" x-cloak');
    expect($html)->toContain('data-test="pillar-show-more-'.$pillar->id.'"')
        ->toContain('>'.trans_choice('strategies.show_more_programs', 2, ['count' => 2]).'</button>');
});

test('a manager edits the strategy details from its page', function () {
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2026, 'end_year' => 2030]);

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::strategies.show', ['strategy' => $strategy])
        ->assertSeeHtml('data-test="edit-strategy-button"')
        ->call('openEditModal', $strategy->id)
        ->assertSet('showStrategyModal', true)
        ->set('name', 'Country Strategy 2026')
        ->call('saveStrategy')
        ->assertHasNoErrors()
        ->assertSet('showStrategyModal', false)
        ->assertSeeHtml('<h1 class="page-title">Country Strategy 2026</h1>');

    expect($strategy->fresh()->name)->toBe('Country Strategy 2026');
});

test('deleting a strategy from its page goes back to the list', function () {
    $strategy = Strategy::factory()->create();

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.show', ['strategy' => $strategy])
        ->assertSeeHtml('wire:click="confirmDelete('.$strategy->id.')"')
        ->call('confirmDelete', $strategy->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteStrategy')
        ->assertRedirect(route('strategies.index'));

    $this->assertModelMissing($strategy);
});

test('a strategy with programs shows why it cannot be deleted and stays', function () {
    $strategy = Strategy::factory()->create();
    Program::factory()->create(['strategy_id' => $strategy->id]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.show', ['strategy' => $strategy])
        ->assertSeeHtml('data-test="delete-strategy-reason">'.e(__('strategies.cannot_delete_has_programs')).'</p>')
        ->assertDontSeeHtml('wire:click="confirmDelete(')
        ->call('confirmDelete', $strategy->id)
        ->call('deleteStrategy')
        ->assertNoRedirect();

    $this->assertModelExists($strategy);
});

test('a viewer sees no edit button and no actions menu', function () {
    $strategy = Strategy::factory()->create();

    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('strategies.show', $strategy))
        ->assertDontSee('data-test="edit-strategy-button"', false)
        ->assertDontSee('data-test="strategy-more-actions"', false);
});

test('an empty strategy page shows one not-set-up line, and hides the empty build section from a viewer', function () {
    $strategy = Strategy::factory()->create(['name' => 'Empty Strategy', 'start_year' => 2026, 'end_year' => 2030]);

    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('strategies.show', $strategy))
        ->assertOk()
        ->assertSeeText(__('strategies.not_set_up'))
        ->assertSeeText(__('strategies.not_set_up_viewer'))
        ->assertDontSeeText(__('strategies.nothing_needs_attention'))
        ->assertDontSeeText(__('strategies.verdict_none'))
        ->assertDontSee('data-test="strategy-pillars"', false);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.show', $strategy))
        ->assertSee('data-test="strategy-pillars"', false)
        ->assertSee('data-test="create-pillar"', false);
});
