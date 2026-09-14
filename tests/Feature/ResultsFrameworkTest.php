<?php

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the results framework', function () {
    $this->get(route('results.index'))->assertRedirect(route('login'));
});

test('results render as a nested logframe grouped by program', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create(['name' => 'Water Security']);

    $impact = Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Reduced waterborne disease.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $program->id,
        'parent_id' => $impact->id,
        'statement' => 'Improved access to safe water.',
        'type' => ResultType::Outcome,
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Water Security')
        ->assertSee('Reduced waterborne disease.')
        ->assertSee('Improved access to safe water.')
        ->assertSee('data-tree-depth="1"', false);
});

test('a result with no program still appears under unassigned', function () {
    $user = User::factory()->create();

    Result::factory()->create([
        'program_id' => null,
        'statement' => 'Orphaned statement.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Unassigned')
        ->assertSee('Orphaned statement.');
});

test('results can be filtered by program and type', function () {
    $user = User::factory()->create();
    $first = Program::factory()->create();
    $second = Program::factory()->create();

    Result::factory()->create(['program_id' => $first->id, 'statement' => 'First impact.', 'type' => ResultType::Impact]);
    Result::factory()->create(['program_id' => $second->id, 'statement' => 'Second output.', 'type' => ResultType::Output]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('program', (string) $first->id)
        ->assertSee('First impact.')
        ->assertDontSee('Second output.')
        ->call('clearFilters')
        ->set('type', ResultType::Output->value)
        ->assertDontSee('First impact.')
        ->assertSee('Second output.');
});

test('a result can be created under a parent', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $parent = Result::factory()->create(['program_id' => $program->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal')
        ->set('statement', '  Latrines constructed and handed over.  ')
        ->set('resultType', ResultType::Output->value)
        ->set('checkoutPeriod', Period::Quarterly->value)
        ->set('resultProgramId', (string) $program->id)
        ->set('parentId', (string) $parent->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    $result = Result::query()->where('statement', 'Latrines constructed and handed over.')->sole();

    expect($result->parent_id)->toBe($parent->id)
        ->and($result->type)->toBe(ResultType::Output);
});

test('the parent picker excludes the edited result and its own descendants', function () {
    $user = User::factory()->create();

    $root = Result::factory()->create(['statement' => 'Root statement.']);
    Result::factory()->create(['parent_id' => $root->id, 'statement' => 'Child statement.']);
    Result::factory()->create(['statement' => 'Unrelated statement.']);

    $component = Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openEditModal', $root->id);

    $statements = $component->instance()->parentOptions()->pluck('statement')->all();

    expect($statements)->toContain('Unrelated statement.')
        ->not->toContain('Root statement.')
        ->not->toContain('Child statement.');
});

test('a leaf result with no indicators can be deleted', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult')
        ->assertHasNoErrors();

    expect(DB::table('results')->find($result->id))->toBeNull();
});

test('a result with children is not deleted because the subtree would cascade', function () {
    $user = User::factory()->create();
    $parent = Result::factory()->create();
    $child = Result::factory()->create(['parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $parent->id)
        ->call('deleteResult');

    expect(DB::table('results')->find($parent->id))->not->toBeNull()
        ->and(DB::table('results')->find($child->id))->not->toBeNull();
});

test('a result carrying indicators is not deleted because targets and reports would cascade', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create(['result_id' => $result->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult');

    expect(DB::table('results')->find($result->id))->not->toBeNull()
        ->and(DB::table('indicators')->find($indicator->id))->not->toBeNull();
});

test('the results framework renders with no results', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::results.index')->assertSee('No results yet');
});
