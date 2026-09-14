<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Result;
use App\Models\User;
use App\Models\YearlyPlan;
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
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
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
