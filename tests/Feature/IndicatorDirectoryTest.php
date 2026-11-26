<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\RagStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the indicator directory', function () {
    $this->get(route('indicators.index'))->assertRedirect(route('login'));
});

test('indicators can be filtered by search result and measure', function () {
    $user = User::factory()->role('General Manager')->create();
    $first = Result::factory()->create(['statement' => 'Access improved.']);
    $second = Result::factory()->create(['statement' => 'Hygiene improved.']);

    Indicator::factory()->create(['result_id' => $first->id, 'name' => 'People reached', 'measure' => IndicatorMeasure::Numeric]);
    Indicator::factory()->create(['result_id' => $second->id, 'name' => 'Share satisfied', 'measure' => IndicatorMeasure::Percentage]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('People reached')
        ->assertSee('Share satisfied')
        ->set('search', 'reached')
        ->assertSee('People reached')
        ->assertDontSee('Share satisfied')
        ->call('clearFilters')
        ->set('result', (string) $second->id)
        ->assertDontSee('People reached')
        ->assertSee('Share satisfied')
        ->call('clearFilters')
        ->set('measure', IndicatorMeasure::Numeric->value)
        ->assertSee('People reached')
        ->assertDontSee('Share satisfied');
});

test('an indicator can be created against a result', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('addForResult', $result->id)
        ->set('entry.name_ar', '  Litres per person per day  ')
        ->assertSet('entryResultId', (string) $result->id)
        ->set('entry.measure', IndicatorMeasure::Numeric->value)
        ->call('saveEntry')
        ->assertHasNoErrors();

    $indicator = Indicator::query()->where('name', 'Litres per person per day')->sole();

    expect($indicator->result_id)->toBe($result->id)
        ->and($indicator->measure)->toBe(IndicatorMeasure::Numeric);
});

test('creating an indicator requires a name and a result', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('addForResult')
        ->set('entry.name_ar', '')
        ->assertSet('entryResultId', '')
        ->call('saveEntry')
        ->assertHasErrors(['entryResultId']);
});

test('an indicator can be added with a unit and frequency then given logframe values at a location row', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $row = ProposalLocation::factory()->create(['program_id' => Program::factory()->create()->id]);

    Livewire::actingAs($user)
        ->test('indicators.quick-form', ['initialResultId' => $result->id, 'initialLocationId' => $row->id, 'openOnLoad' => true])
        ->set('entry.name_ar', 'Households with safe water access')
        ->set('entry.unit', 'households')
        ->set('entry.frequency', Period::Monthly->value)
        ->call('saveEntry')
        ->assertHasNoErrors();

    $indicator = Indicator::query()->where('name', 'Households with safe water access')->sole();

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('editIndicator', $indicator->id)
        ->set('baseline', '100')
        ->set('target', '500')
        ->call('saveIndicator')
        ->assertHasNoErrors();

    $indicator->refresh();

    expect($indicator->unit)->toBe('households')
        ->and((float) $indicator->baseline)->toBe(100.0)
        ->and((float) $indicator->target)->toBe(500.0)
        ->and($indicator->frequency)->toBe(Period::Monthly)
        ->and($indicator->proposal_location_id)->toBe($row->id);
});

test('an indicator unit longer than the column can hold is rejected', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('addForResult', $result->id)
        ->set('entry.name_ar', 'Indicator with overlong unit')
        ->assertSet('entryResultId', (string) $result->id)
        ->set('entry.measure', IndicatorMeasure::Numeric->value)
        ->set('entry.unit', str_repeat('a', 51))
        ->call('saveEntry')
        ->assertHasErrors(['entry.unit']);

    expect(Indicator::query()->where('name', 'Indicator with overlong unit')->exists())->toBeFalse();
});

test('a reading can be logged against an indicator', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create(['baseline' => 100, 'target' => 500]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openProgressModal', $indicator->id)
        ->set('progressDate', '2026-01-15')
        ->set('progressValue', '250')
        ->set('progressNotes', 'Mid-year household survey.')
        ->set('progressSourceOfVerification', 'Household survey')
        ->call('saveProgress')
        ->assertHasNoErrors();

    $reading = IndicatorProgress::query()->where('indicator_id', $indicator->id)->sole();

    expect((float) $reading->value)->toBe(250.0)
        ->and($reading->source_of_verification)->toBe('Household survey')
        ->and($reading->recorded_by)->toBe($user->id);
});

test('an indicator with no target shows no percent and reads as not measured yet', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->untargeted()->create(['name' => 'Untargeted indicator']);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('Untargeted indicator')
        ->assertSee('Not measured yet');

    expect($indicator->percentAchieved())->toBeNull();
});

test('an untargeted indicator can be deleted', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->call('deleteIndicator')
        ->assertHasNoErrors();

    expect(DB::table('indicators')->find($indicator->id))->toBeNull();
});

test('an indicator carrying a target is not deleted because targets and reports would cascade', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create();
    $target = PlanIndicator::factory()->create([
        'indicator_id' => $indicator->id,
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->call('deleteIndicator');

    expect(DB::table('indicators')->find($indicator->id))->not->toBeNull()
        ->and(DB::table('plan_indicators')->find($target->id))->not->toBeNull();
});

test('the indicator directory renders with no indicators', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('pages::indicators.index')->assertSee('No indicators yet');
});

test('the rating band narrows the directory to indicators rated that colour', function () {
    $user = User::factory()->role('General Manager')->create();

    // Sitting on its target all year rates green; sitting on its baseline rates red.
    $green = Indicator::factory()->create(['name' => 'Wells fully repaired', 'baseline' => 0, 'target' => 100]);
    IndicatorProgress::factory()->create(['indicator_id' => $green->id, 'value' => 100, 'date' => now()->toDateString()]);

    $red = Indicator::factory()->create(['name' => 'Latrines fully repaired', 'baseline' => 0, 'target' => 100]);
    IndicatorProgress::factory()->create(['indicator_id' => $red->id, 'value' => 1, 'date' => now()->toDateString()]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('Wells fully repaired')
        ->assertSee('Latrines fully repaired')
        ->call('setRag', RagStatus::Red->value)
        ->assertSee('Latrines fully repaired')
        ->assertDontSee('Wells fully repaired')
        // Clicking the active band again releases it.
        ->call('setRag', RagStatus::Red->value)
        ->assertSet('rag', '')
        ->assertSee('Wells fully repaired');
});

test('the attention chips narrow the directory and count what they would show', function () {
    $user = User::factory()->role('General Manager')->create();

    $measured = Indicator::factory()->create(['name' => 'Measured indicator']);
    IndicatorProgress::factory()->create(['indicator_id' => $measured->id, 'date' => now()->toDateString()]);

    Indicator::factory()->create(['name' => 'Silent indicator']);
    Indicator::factory()->untargeted()->create(['name' => 'Aimless indicator']);

    $component = Livewire::actingAs($user)->test('pages::indicators.index');

    expect($component->get('focusCounts')['unmeasured'])->toBe(2)
        ->and($component->get('focusCounts')['untargeted'])->toBe(1);

    $component
        ->call('setFocus', 'unmeasured')
        ->assertSee('Silent indicator')
        ->assertDontSee('Measured indicator')
        ->call('setFocus', 'untargeted')
        ->assertSee('Aimless indicator')
        ->assertDontSee('Silent indicator')
        ->call('setFocus', 'untargeted')
        ->assertSet('focus', '')
        ->assertSee('Measured indicator');
});

test('an indicator whose last reading is older than its own frequency needs a reading', function () {
    $user = User::factory()->role('General Manager')->create();

    $stale = Indicator::factory()->measuredEvery(Period::Monthly)->create(['name' => 'Monthly indicator']);
    IndicatorProgress::factory()->create(['indicator_id' => $stale->id, 'date' => now()->subMonths(3)->toDateString()]);

    $fresh = Indicator::factory()->measuredEvery(Period::Annual)->create(['name' => 'Annual indicator']);
    IndicatorProgress::factory()->create(['indicator_id' => $fresh->id, 'date' => now()->subMonths(3)->toDateString()]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('setFocus', 'stale')
        ->assertSee('Monthly indicator')
        ->assertDontSee('Annual indicator');
});

test('the chain inspector shows the readings and the targets behind one indicator', function () {
    $user = User::factory()->role('General Manager')->create();
    $recorder = User::factory()->role('General Manager')->create(['name' => 'Rana Saleh']);
    $indicator = Indicator::factory()->create(['name' => 'Households reached']);

    IndicatorProgress::factory()->create([
        'indicator_id' => $indicator->id,
        'value' => 120,
        'date' => now()->subMonth()->toDateString(),
        'source_of_verification' => 'Household survey',
        'recorded_by' => $recorder->id,
    ]);

    PlanIndicator::factory()->create([
        'indicator_id' => $indicator->id,
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openInspectModal', $indicator->id)
        ->assertSet('showInspectModal', true)
        ->assertSet('inspectingIndicatorId', $indicator->id)
        ->assertSee('Household survey')
        ->assertSee('Recorded by Rana Saleh')
        ->assertSee('Annual plan 2026')
        ->call('closeInspectModal')
        ->assertSet('showInspectModal', false)
        ->assertSet('inspectingIndicatorId', null);
});

test('the delete and reading dialogs are driven by a boolean the modal can bind to', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingIndicatorId', null)
        ->call('openProgressModal', $indicator->id)
        ->assertSet('showProgressModal', true)
        ->call('closeProgressModal')
        ->assertSet('showProgressModal', false);
});

test('a new reading refreshes the figures in the same request', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create(['baseline' => 0, 'target' => 200]);

    $component = Livewire::actingAs($user)->test('pages::indicators.index');

    expect($component->get('overview')['readings'])->toBe(0);

    $component
        ->call('openProgressModal', $indicator->id)
        ->set('progressValue', '150')
        ->call('saveProgress')
        ->assertHasNoErrors();

    expect($component->get('overview')['readings'])->toBe(1);
});

test('the directory reads a flat number of queries however many indicators there are', function () {
    $user = User::factory()->role('General Manager')->create();

    $seed = function (int $indicators): void {
        foreach (range(1, $indicators) as $index) {
            $result = Result::factory()->create();
            $indicator = Indicator::factory()->create(['result_id' => $result->id]);
            IndicatorProgress::factory()->count(2)->create(['indicator_id' => $indicator->id]);
            $target = PlanIndicator::factory()->create([
                'indicator_id' => $indicator->id,
                'source_type' => 'annual_plan',
                'source_id' => AnnualPlan::factory()->create()->id,
            ]);
            Report::factory()->count(2)->create(['plan_indicator_id' => $target->id]);
        }
    };

    $countQueries = function () use ($user): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($user)->test('pages::indicators.index')->call('openInspectModal', Indicator::query()->value('id'));

        return $queries;
    };

    $seed(2);
    $small = $countQueries();

    $seed(8);
    $large = $countQueries();

    // Eager loading is what holds this flat; a dropped `with()` turns the
    // difference into one query per indicator, reading or target.
    expect($large)->toBeLessThanOrEqual($small + 2);
});

test('the page shows what was achieved against what was committed, counting approved reports only', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create(['name' => 'Meals distributed']);

    $target = PlanIndicator::factory()->create([
        'indicator_id' => $indicator->id,
        'target' => 1000,
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
    ]);

    Report::factory()->create(['plan_indicator_id' => $target->id, 'value' => 400, 'status' => ReportStatus::Approved]);
    Report::factory()->create(['plan_indicator_id' => $target->id, 'value' => 150, 'status' => ReportStatus::Draft]);

    $component = Livewire::actingAs($user)->test('pages::indicators.index');
    $row = $component->instance()->rows()[$indicator->id]['targets'][0];

    expect($row['achieved'])->toBe(400.0)
        ->and($row['pending'])->toBe(150.0)
        ->and($row['status'])->toBe('in_progress')
        ->and($component->get('overview')['targetsAchieved'])->toBe(0);

    $component->call('openInspectModal', $indicator->id)
        ->assertSee('150 pending approval')
        ->assertSeeHtml('href="'.e(route('reports.index', ['create' => 1, 'target' => $target->id])).'"');
});

test('a draft report past its planned date counts as reporting overdue', function () {
    $user = User::factory()->role('General Manager')->create();

    $late = Indicator::factory()->create(['name' => 'Late reporter']);
    $lateTarget = PlanIndicator::factory()->create([
        'indicator_id' => $late->id,
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);
    Report::factory()->create([
        'plan_indicator_id' => $lateTarget->id,
        'status' => ReportStatus::Draft,
        'planned_date' => now()->subMonth()->toDateString(),
    ]);

    $punctual = Indicator::factory()->create(['name' => 'Punctual reporter']);
    $punctualTarget = PlanIndicator::factory()->create([
        'indicator_id' => $punctual->id,
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);
    // Approved, so being past its date is not an outstanding obligation.
    Report::factory()->create([
        'plan_indicator_id' => $punctualTarget->id,
        'status' => ReportStatus::Approved,
        'planned_date' => now()->subMonth()->toDateString(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::indicators.index');

    expect($component->get('focusCounts')['reporting'])->toBe(1);

    $component
        ->call('setFocus', 'reporting')
        ->assertSee('Late reporter')
        ->assertDontSee('Punctual reporter');
});

test('the chain inspector shows the statements this indicator rolls up through', function () {
    $user = User::factory()->role('General Manager')->create();

    $outcome = Result::factory()->create(['statement' => 'Livelihoods restored.', 'type' => ResultType::Outcome]);
    $output = Result::factory()->create([
        'statement' => 'Grants disbursed.',
        'type' => ResultType::Output,
        'parent_id' => $outcome->id,
    ]);
    $indicator = Indicator::factory()->create(['result_id' => $output->id, 'name' => 'Grants paid']);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openInspectModal', $indicator->id)
        // The statement it measures, and the one that statement rolls up into.
        ->assertSee('Grants disbursed.')
        ->assertSee('Livelihoods restored.');
});

test('the results framework can open the form already tied to a statement, or open one indicator chain', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['create' => $result->id])
        ->test('pages::indicators.index')
        ->assertSet('openIndicatorEntryOnLoad', true)
        ->assertSet('initialIndicatorResultId', $result->id)
        ->assertSeeLivewire('indicators.quick-form');

    Livewire::actingAs($user)
        ->withQueryParams(['inspect' => $indicator->id])
        ->test('pages::indicators.index')
        ->assertSet('showInspectModal', true)
        ->assertSet('inspectingIndicatorId', $indicator->id);
});

test('the project page can open the form already scoped to a location row', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = ProposalLocation::factory()->create(['program_id' => Program::factory()->create()->id]);

    Livewire::actingAs($user)
        ->withQueryParams(['create' => 1, 'location' => $row->id])
        ->test('pages::indicators.index')
        ->assertSet('openIndicatorEntryOnLoad', true)
        ->assertSet('initialIndicatorLocationId', $row->id)
        ->assertSet('initialIndicatorResultId', null);
});

test('an old project deep link opens the form on the row that took the project over', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $row = proposalRowForProject(Proposal::factory()->create(), $project);

    Livewire::actingAs($user)
        ->withQueryParams(['create' => 1, 'project' => $project->id])
        ->test('pages::indicators.index')
        ->assertSet('openIndicatorEntryOnLoad', true)
        ->assertSet('initialIndicatorLocationId', $row->id)
        ->assertSet('initialIndicatorResultId', null);
});

test('a deep link to a row that does not exist opens nothing', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->withQueryParams(['create' => 1, 'location' => 999999])
        ->test('pages::indicators.index')
        ->assertSet('openIndicatorEntryOnLoad', false)
        ->assertSet('initialIndicatorLocationId', null);
});

test('only rows of the result program are offered, and any other is refused', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $result = Result::factory()->for($program)->create();
    $inside = ProposalLocation::factory()->create(['program_id' => $program->id]);
    $outside = ProposalLocation::factory()->create(['program_id' => Program::factory()->create()->id]);
    ProposalLocation::factory()->create(['program_id' => null]);
    $indicator = Indicator::factory()->for($result)->create();

    $component = Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openEditModal', $indicator->id);

    expect($component->instance()->locationOptions()->modelKeys())->toBe([$inside->id]);

    $component
        ->set('name', 'Outside row indicator')
        ->set('indicatorLocationId', (string) $outside->id)
        ->call('saveIndicator')
        ->assertHasErrors(['indicatorLocationId']);

    expect(Indicator::query()->where('name', 'Outside row indicator')->exists())->toBeFalse()
        ->and($indicator->fresh()->proposal_location_id)->not->toBe($outside->id);
});

test('a percentage indicator carries no unit and cannot target more than 100', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('addForResult', $result->id)
        ->set('entry.name_ar', 'Share of households with latrines')
        ->set('entry.unit', 'households')
        ->set('entry.measure', IndicatorMeasure::Percentage->value)
        ->call('saveEntry')
        ->assertHasNoErrors();

    $indicator = Indicator::query()->where('name', 'Share of households with latrines')->sole();

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('editIndicator', $indicator->id)
        ->set('target', '120')
        ->call('saveIndicator')
        ->assertHasErrors(['target' => 'max'])
        ->set('target', '80')
        ->call('saveIndicator')
        ->assertHasNoErrors();

    expect($indicator->fresh()->unit)->toBeNull();
});

test('the measure is locked once a target is committed against the indicator', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create(['measure' => IndicatorMeasure::Numeric]);
    PlanIndicator::factory()->create(['indicator_id' => $indicator->id]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openEditModal', $indicator->id)
        ->set('indicatorMeasure', IndicatorMeasure::Percentage->value)
        ->set('target', '50')
        ->set('baseline', '0')
        ->call('saveIndicator')
        ->assertHasErrors(['indicatorMeasure']);

    expect($indicator->fresh()->measure)->toBe(IndicatorMeasure::Numeric);
});

test('an edit that would put a committed target out of its chain is refused', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $indicator = Indicator::factory()->for(Result::factory()->for($program))->create();
    PlanIndicator::factory()
        ->forAnnualPlan(AnnualPlan::factory()->for($program)->create())
        ->create(['indicator_id' => $indicator->id]);
    $elsewhere = Result::factory()->for(Program::factory())->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openEditModal', $indicator->id)
        ->set('indicatorResultId', (string) $elsewhere->id)
        ->call('saveIndicator')
        ->assertHasErrors(['indicatorResultId']);

    expect($indicator->fresh()->result_id)->not->toBe($elsewhere->id);
});

test('a reading cannot be dated in the future', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openProgressModal', $indicator->id)
        ->set('progressDate', now()->addDay()->toDateString())
        ->set('progressValue', '10')
        ->call('saveProgress')
        ->assertHasErrors(['progressDate' => 'before_or_equal']);

    expect(IndicatorProgress::query()->where('indicator_id', $indicator->id)->exists())->toBeFalse();
});

test('a logged reading can be corrected and deleted, keeping who recorded it', function () {
    $user = User::factory()->role('General Manager')->create();
    $recorder = User::factory()->role('General Manager')->create();
    $reading = IndicatorProgress::factory()->create(['value' => 10, 'recorded_by' => $recorder->id, 'date' => now()->subWeek()->toDateString()]);

    $component = Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openInspectModal', $reading->indicator_id)
        ->call('openEditReadingModal', $reading->id)
        ->assertSet('progressValue', '10.00')
        ->set('progressValue', '12')
        ->call('saveProgress')
        ->assertHasNoErrors();

    $row = DB::table('indicator_progress')->find($reading->id);
    expect((float) $row->value)->toBe(12.0)
        ->and((int) $row->recorded_by)->toBe($recorder->id)
        ->and(DB::table('indicator_progress')->count())->toBe(1);

    $component->call('confirmDeleteReading', $reading->id)
        ->assertSeeHtml('data-test="confirm-delete-reading-'.$reading->id.'"')
        ->call('deleteReading');

    expect(DB::table('indicator_progress')->find($reading->id))->toBeNull();
});

test('a reading of another indicator cannot be deleted from this one', function () {
    $user = User::factory()->role('General Manager')->create();
    $inspected = Indicator::factory()->create();
    $foreign = IndicatorProgress::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openInspectModal', $inspected->id)
        ->call('confirmDeleteReading', $foreign->id);

    $component->call('deleteReading')->assertNotFound();

    expect(DB::table('indicator_progress')->find($foreign->id))->not->toBeNull();
});

test('an inspected target that does not fit its chain says why', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->for(Result::factory()->for(Program::factory()))->create();
    PlanIndicator::factory()
        ->forAnnualPlan(AnnualPlan::factory()->create())
        ->create(['indicator_id' => $indicator->id]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('1 target out of step')
        ->call('openInspectModal', $indicator->id)
        ->assertSee(__('targets.issue_plan_program_differs_from_result'));
});

test('the focus tabs and the indicator list render inside the Livewire component', function () {
    $user = User::factory()->role('General Manager')->create();
    Indicator::factory()->create(['result_id' => Result::factory()->create()->id, 'name' => 'People reached']);

    $html = $this->actingAs($user)->get(route('indicators.index'))->assertOk()->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $root = '//section[@*[name()="wire:name"]="pages::indicators.index"]';

    foreach (['//*[@data-test="focus-tab-attention"]', '//ul[@data-test="indicator-list"]//li'] as $inner) {
        expect($xpath->query($root.$inner)->length)->toBeGreaterThan(0, "{$inner} sits outside the component");
    }
});

test('a headline average caps each indicator at one hundred percent', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create([
        'result_id' => $result->id,
        'measure' => IndicatorMeasure::Numeric,
        'baseline' => 0,
        'target' => 100,
    ]);
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => 1500, 'date' => now()->subDay()]);
    Indicator::factory()->create(['result_id' => $result->id]);

    $component = Livewire::actingAs($user)->test('pages::indicators.index');

    expect($component->get('overview')['achieved'])->toBeLessThanOrEqual(100.0);
});

test('indicators can be filtered by the donor funding their targets', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $funded = Indicator::factory()->create(['result_id' => $result->id, 'name' => 'Funded indicator']);
    Indicator::factory()->create(['result_id' => $result->id, 'name' => 'Unfunded indicator']);

    $donor = Donor::factory()->create(['name' => 'Caritas Test']);
    $proposal = Proposal::factory()->create(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $donor->id]);
    PlanIndicator::factory()->create([
        'indicator_id' => $funded->id,
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('Unfunded indicator')
        ->set('donor', 'Caritas Test')
        ->assertSee('Funded indicator')
        ->assertDontSee('Unfunded indicator')
        ->call('clearFilters')
        ->assertSet('donor', '')
        ->assertSee('Unfunded indicator');
});

test('the filtered indicators download as a CSV without formula injection', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    Indicator::factory()->create(['result_id' => $result->id, 'name' => '=HYPERLINK("x")']);
    Indicator::factory()->create(['result_id' => $result->id, 'name' => 'Left out']);

    $component = Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->set('search', 'HYPERLINK')
        ->call('exportIndicators')
        ->assertFileDownloaded('indicators-'.now()->toDateString().'.csv');

    ob_start();
    $response = $component->instance()->exportIndicators();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain("'=HYPERLINK")->not->toContain('Left out');
});

test('an indicator carrying targets offers a disabled delete that says why', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create();
    PlanIndicator::factory()->create([
        'indicator_id' => $indicator->id,
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee(__('indicators.indicators_carrying_targets_cannot_be_deleted'));
});

test('a reading far above the target shows a warning but can still be saved', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create(['measure' => IndicatorMeasure::Numeric, 'baseline' => 0, 'target' => 100]);

    $component = Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openProgressModal', $indicator->id)
        ->set('progressValue', '150');

    expect($component->get('progressValueWarning'))->toBeNull();

    $component->set('progressValue', '250');
    expect($component->get('progressValueWarning'))->toContain('250%');

    $component->call('saveProgress')->assertHasNoErrors();
    expect($indicator->progress()->count())->toBe(1);
});

test('the result list names the program so similar results can be told apart', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create(['name_en' => 'Study Zone']);
    Result::factory()->create(['program_id' => $program->id, 'statement' => 'Students supported.']);

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->call('addForResult')
        ->assertSeeInOrder(['Study Zone', 'Students supported.']);
});

test('the delete dialog names the indicator', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicator = Indicator::factory()->create(['name' => 'Meals distributed']);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->assertSet('showDeleteModal', true)
        ->assertSee('“Meals distributed” will be deleted.')
        ->assertDontSee('the money behind it');
});

test('each indicator shows one plain status and what needs a hand comes first', function () {
    $user = User::factory()->role('General Manager')->create();
    $result = Result::factory()->create();
    $fresh = Indicator::factory()->create(['result_id' => $result->id, 'name' => 'AAA on track', 'baseline' => 0, 'target' => 100, 'frequency' => Period::Annual]);
    IndicatorProgress::factory()->create(['indicator_id' => $fresh->id, 'value' => 100, 'date' => now()->subDay()]);
    $old = Indicator::factory()->create(['result_id' => $result->id, 'name' => 'BBB old reading', 'baseline' => 0, 'target' => 100, 'frequency' => Period::Monthly]);
    IndicatorProgress::factory()->create(['indicator_id' => $old->id, 'value' => 100, 'date' => now()->subMonths(8)]);
    Indicator::factory()->create(['result_id' => $result->id, 'name' => 'CCC never measured']);

    $component = Livewire::actingAs($user)->test('pages::indicators.index');
    $rows = $component->instance()->rows();

    expect($rows[$fresh->id]['status'])->toBe('ontrack')
        ->and($rows[$old->id]['status'])->toBe('stale');

    $component
        ->assertSeeInOrder(['BBB old reading', 'CCC never measured', 'AAA on track'])
        ->assertSee('Reading is old')
        ->assertSee('Waiting for a reading');

    expect($component->get('focusCounts'))->toMatchArray(['ontrack' => 1, 'waiting' => 2, 'attention' => 0]);

    $component->call('setFocus', 'waiting')
        ->assertSee('BBB old reading')
        ->assertDontSee('AAA on track')
        ->assertSeeHtml('data-test="focus-tab-clear-waiting"')
        ->assertSeeText('Showing 2 of 3 indicators.')
        ->assertSeeHtml('wire:click="setFocus(&#039;waiting&#039;)" class="cursor-pointer')
        ->call('setFocus', 'waiting')
        ->assertDontSeeHtml('data-test="focus-showing"')
        ->assertDontSeeHtml('data-test="focus-tab-clear-waiting"');
});
