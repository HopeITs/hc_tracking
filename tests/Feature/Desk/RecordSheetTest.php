<?php

use App\Models\Milestone;
use App\Models\Program;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('a record page opens as a sheet over its register, which loads after it', function () {
    $task = Task::factory()->create(['title' => 'Draft the distribution plan']);

    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee('class="dk-scope"', false)
        ->assertSee('data-desk-record-host', false)
        ->assertSee('role="dialog" aria-modal="true"', false)
        ->assertSee('href="'.route('tasks.index').'" wire:navigate class="dk-iconbtn"', false)
        ->assertSee('Draft the distribution plan')
        ->assertDontSee('data-desk-register-view', false);
});

test('the register behind the sheet is inert so only the sheet takes focus', function () {
    $milestone = Milestone::factory()->create();

    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('milestones.show', $milestone))
        ->assertOk()
        ->assertSee('class="dk-record-behind" inert aria-hidden="true"', false);
});

test('a program opens over the projects register filtered to it', function () {
    $program = Program::factory()->create();

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeHtml('href="'.e(route('projects.index', ['program' => $program->id])).'"');
});

test('a record the viewer may not open is still refused', function () {
    $task = Task::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('tasks.show', $task))
        ->assertForbidden();
});
