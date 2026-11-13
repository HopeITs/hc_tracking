<?php

use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Program;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the indicator directory can be narrowed to the level of the result measured', function () {
    $user = mealManager();
    $outcome = Result::factory()->type(ResultType::Outcome)->create();
    $output = Result::factory()->type(ResultType::Output)->create();
    Indicator::factory()->create(['result_id' => $outcome->id, 'name' => 'Share of families with safe water']);
    Indicator::factory()->create(['result_id' => $output->id, 'name' => 'Wells drilled']);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->set('level', ResultType::Output->value)
        ->assertSee('Wells drilled')
        ->assertDontSee('Share of families with safe water')
        ->call('clearFilters')
        ->assertSet('level', '')
        ->assertSee('Share of families with safe water');
});

test('the framework adds and edits indicators in place instead of linking to the directory', function () {
    $user = mealManager();
    $unmeasured = Result::factory()->type(ResultType::Outcome)->create();
    $measured = Result::factory()->type(ResultType::Outcome)->create();
    $indicator = Indicator::factory()->create(['result_id' => $measured->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSeeHtml('data-test="add-indicator-for-result-'.$unmeasured->id.'"')
        ->assertSeeHtml('data-test="edit-indicator-'.$indicator->id.'"')
        ->assertDontSeeHtml('href="'.route('indicators.index', ['create' => $unmeasured->id]).'"');
});

test('someone who may not write indicators gets no add or edit control on the framework', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('results.view');
    $unmeasured = Result::factory()->type(ResultType::Outcome)->create();
    $indicator = Indicator::factory()->create(['result_id' => Result::factory()->type(ResultType::Outcome)->create()->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertDontSeeHtml('data-test="add-indicator-for-result-'.$unmeasured->id.'"')
        ->assertDontSeeHtml('data-test="edit-indicator-'.$indicator->id.'"');

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->dispatch('add-indicator', resultId: $unmeasured->id)
        ->assertForbidden();
});

test('an indicator added on the framework is tied to its statement and lands in the library', function () {
    $user = mealManager();
    $program = Program::factory()->create();
    $impact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id]);
    Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id, 'parent_id' => $impact->id]);
    $outcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id, 'parent_id' => $impact->id]);
    $output = Result::factory()->type(ResultType::Output)->create(['program_id' => $program->id, 'parent_id' => $outcome->id]);

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->dispatch('add-indicator', resultId: $output->id)
        ->assertSet('showIndicatorModal', true)
        ->assertSet('indicatorResultId', (string) $output->id)
        ->set('name', 'Wells drilled')
        ->set('unit', 'wells')
        ->call('saveIndicator')
        ->assertHasNoErrors()
        ->assertSet('showIndicatorModal', false)
        ->assertDispatched('indicator-saved');

    $indicator = Indicator::query()->where('name', 'Wells drilled')->sole();
    $entry = LibraryIndicator::query()->sole();

    expect($indicator->result_id)->toBe($output->id)
        ->and($indicator->library_indicator_id)->toBe($entry->id)
        ->and($indicator->code)->toBe($entry->code)
        ->and($entry->name_ar)->toBe('Wells drilled')
        ->and($entry->program_id)->toBe($program->id)
        ->and($entry->level_ref)->toBe('OP2.1')
        ->and($entry->suggested_level)->toBe(ResultType::Output)
        ->and($entry->measure)->toBe(IndicatorMeasure::Numeric)
        ->and($entry->unit)->toBe('wells');
});

test('a new indicator reuses the library entry that already carries its name', function () {
    $user = mealManager();
    $program = Program::factory()->create();
    $outcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id]);
    $entry = LibraryIndicator::factory()->create(['program_id' => $program->id, 'name_ar' => 'People reached', 'measure' => IndicatorMeasure::Numeric]);
    LibraryIndicator::factory()->create(['program_id' => Program::factory()->create()->id, 'name_ar' => 'People reached']);

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->dispatch('add-indicator', resultId: $outcome->id)
        ->set('name', 'People reached')
        ->call('saveIndicator')
        ->assertHasNoErrors();

    expect(LibraryIndicator::query()->count())->toBe(2)
        ->and(Indicator::query()->where('name', 'People reached')->sole()->library_indicator_id)->toBe($entry->id);
});

test('an indicator is kept when its program has no HR code for a library entry', function () {
    $user = mealManager();
    $program = Program::factory()->create();
    DB::connection('hr')->table('programs')->where('id', $program->id)->update(['code' => '']);
    $outcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $program->id]);

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->dispatch('add-indicator', resultId: $outcome->id)
        ->set('name', 'People reached')
        ->call('saveIndicator')
        ->assertHasNoErrors();

    expect(Indicator::query()->where('name', 'People reached')->sole()->library_indicator_id)->toBeNull()
        ->and(LibraryIndicator::query()->count())->toBe(0);
});

test('editing an indicator from the framework changes it without a second library entry', function () {
    $user = mealManager();
    $indicator = Indicator::factory()->create(['name' => 'People reached']);

    Livewire::actingAs($user)
        ->test('indicators.quick-form')
        ->dispatch('edit-indicator', indicatorId: $indicator->id)
        ->assertSet('name', 'People reached')
        ->set('name', 'People reached with safe water')
        ->call('saveIndicator')
        ->assertHasNoErrors()
        ->assertDispatched('indicator-saved');

    expect($indicator->fresh()->name)->toBe('People reached with safe water')
        ->and(LibraryIndicator::query()->count())->toBe(0);
});
