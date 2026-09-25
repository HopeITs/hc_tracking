<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\RagStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the indicator directory', function () {
    $this->get(route('indicators.index'))->assertRedirect(route('login'));
});

test('indicators can be filtered by search result and measure', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', '  Litres per person per day  ')
        ->set('indicatorResultId', (string) $result->id)
        ->set('indicatorMeasure', IndicatorMeasure::Numeric->value)
        ->call('saveIndicator')
        ->assertHasNoErrors();

    $indicator = Indicator::query()->where('name', 'Litres per person per day')->sole();

    expect($indicator->result_id)->toBe($result->id)
        ->and($indicator->measure)->toBe(IndicatorMeasure::Numeric);
});

test('creating an indicator requires a name and a result', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('indicatorResultId', '')
        ->call('saveIndicator')
        ->assertHasErrors(['name', 'indicatorResultId']);
});

test('an indicator can be created with a unit baseline target frequency and project', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', 'Households with safe water access')
        ->set('indicatorResultId', (string) $result->id)
        ->set('indicatorMeasure', IndicatorMeasure::Numeric->value)
        ->set('unit', 'households')
        ->set('baseline', '100')
        ->set('target', '500')
        ->set('frequency', Period::Monthly->value)
        ->set('indicatorProjectId', (string) $project->id)
        ->call('saveIndicator')
        ->assertHasNoErrors();

    $indicator = Indicator::query()->where('name', 'Households with safe water access')->sole();

    expect($indicator->unit)->toBe('households')
        ->and((float) $indicator->baseline)->toBe(100.0)
        ->and((float) $indicator->target)->toBe(500.0)
        ->and($indicator->frequency)->toBe(Period::Monthly)
        ->and($indicator->project_id)->toBe($project->id);
});

test('an indicator unit longer than the column can hold is rejected', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', 'Indicator with overlong unit')
        ->set('indicatorResultId', (string) $result->id)
        ->set('indicatorMeasure', IndicatorMeasure::Numeric->value)
        ->set('unit', str_repeat('a', 51))
        ->call('saveIndicator')
        ->assertHasErrors(['unit']);

    expect(Indicator::query()->where('name', 'Indicator with overlong unit')->exists())->toBeFalse();
});

test('a reading can be logged against an indicator', function () {
    $user = User::factory()->create();
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

test('an indicator with no target shows an em dash instead of percent achieved', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->untargeted()->create(['name' => 'Untargeted indicator']);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('Untargeted indicator')
        ->assertSee('—');

    expect($indicator->percentAchieved())->toBeNull();
});

test('an untargeted indicator can be deleted', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->call('deleteIndicator')
        ->assertHasNoErrors();

    expect(DB::table('indicators')->find($indicator->id))->toBeNull();
});

test('an indicator carrying a target is not deleted because targets and reports would cascade', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::indicators.index')->assertSee('No indicators yet');
});

test('the rating band narrows the directory to indicators rated that colour', function () {
    $user = User::factory()->create();

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
    $user = User::factory()->create();

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
    $user = User::factory()->create();

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
    $user = User::factory()->create();
    $recorder = User::factory()->create(['name' => 'Rana Saleh']);
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
    $user = User::factory()->create();
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

test('the table view renders the same indicators as the cards', function () {
    $user = User::factory()->create();
    Indicator::factory()->create(['name' => 'Wells rehabilitated']);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->set('view', 'table')
        ->assertSee('Wells rehabilitated')
        ->assertSee('Baseline to target');
});

test('a new reading refreshes the figures in the same request', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();

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
    $user = User::factory()->create();
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
        ->assertSee('0 of 1 targets achieved')
        ->assertSee('150 pending approval')
        ->assertSeeHtml('href="'.e(route('reports.index', ['create' => 1, 'target' => $target->id])).'"');
});

test('a draft report past its planned date counts as reporting overdue', function () {
    $user = User::factory()->create();

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
    $user = User::factory()->create();

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
    $user = User::factory()->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['create' => $result->id])
        ->test('pages::indicators.index')
        ->assertSet('showIndicatorModal', true)
        ->assertSet('indicatorResultId', (string) $result->id);

    Livewire::actingAs($user)
        ->withQueryParams(['inspect' => $indicator->id])
        ->test('pages::indicators.index')
        ->assertSet('showInspectModal', true)
        ->assertSet('inspectingIndicatorId', $indicator->id);
});

test('only projects delivering the result program are offered, and any other is refused', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $result = Result::factory()->for($program)->create();
    $inside = Project::factory()->for($program)->create();
    $outside = Project::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('indicatorResultId', (string) $result->id);

    expect($component->instance()->projectOptions()->modelKeys())->toBe([$inside->id]);

    $component
        ->set('name', 'Outside project indicator')
        ->set('indicatorProjectId', (string) $outside->id)
        ->call('saveIndicator')
        ->assertHasErrors(['indicatorProjectId']);

    expect(Indicator::query()->where('name', 'Outside project indicator')->exists())->toBeFalse();
});

test('a percentage indicator carries no unit and cannot target more than 100', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', 'Share of households with latrines')
        ->set('indicatorResultId', (string) $result->id)
        ->set('unit', 'households')
        ->set('indicatorMeasure', IndicatorMeasure::Percentage->value)
        ->assertSet('unit', '')
        ->set('target', '120')
        ->call('saveIndicator')
        ->assertHasErrors(['target' => 'max'])
        ->set('target', '80')
        ->call('saveIndicator')
        ->assertHasNoErrors();

    expect(Indicator::query()->where('name', 'Share of households with latrines')->sole()->unit)->toBeNull();
});

test('the measure is locked once a target is committed against the indicator', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $recorder = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
