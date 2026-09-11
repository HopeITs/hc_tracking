<?php

use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\User;
use App\Models\YearlyPlan;
use Livewire\Livewire;

test('guests are redirected from program detail', function () {
    $program = Program::factory()->create();

    $this->get(route('programs.show', $program))->assertRedirect(route('login'));
});

test('program detail shows the program its strategy and its manager', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create(['name' => 'Layla Haddad']);
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);
    $program = Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager->id,
    ]);

    $this->actingAs($user)
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertSeeText('Water Security')
        ->assertSeeText('Country Strategy')
        ->assertSeeText('Layla Haddad');
});

test('program detail lists only its own projects', function () {
    $user = User::factory()->create();
    $subject = Program::factory()->create();
    $other = Program::factory()->create();

    Project::factory()->create(['name' => 'Aleppo Wells', 'program_id' => $subject->id]);
    Project::factory()->create(['name' => 'Homs Shelter', 'program_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $subject])
        ->assertSet('tab', 'projects')
        ->assertSee('Aleppo Wells')
        ->assertDontSee('Homs Shelter');
});

test('program detail switches to annual plans and results', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    YearlyPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Improved access to safe water.',
        'type' => ResultType::Impact,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('FY 2026')
        ->set('tab', 'results')
        ->assertSee('Improved access to safe water.');
});

test('program detail renders the results logframe as a tree', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $impact = Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Root impact statement.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $program->id,
        'parent_id' => $impact->id,
        'statement' => 'Nested outcome statement.',
        'type' => ResultType::Outcome,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'results')
        ->assertSee('Root impact statement.')
        ->assertSee('Nested outcome statement.')
        ->assertSee('data-tree-depth="1"', false);
});

test('program detail renders a program with nothing attached', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create(['name' => 'Bare Program']);

    $this->actingAs($user)
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertSeeText('Bare Program')
        ->assertSeeText('No projects yet');
});
