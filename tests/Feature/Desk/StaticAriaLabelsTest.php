<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

test('program cards print their open and edit labels instead of handing PHP to Alpine', function () {
    $program = Program::factory()->create(['name' => 'Children University']);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::programs.index')
        ->assertSeeHtml('aria-label="'.e(__('programs.open_name', ['name' => $program->name])).'"')
        ->assertSeeHtml('aria-label="'.e(__('common.edit_name', ['name' => $program->name])).'"')
        ->assertDontSeeHtml(':aria-label="__(');
});

test('the strategy coverage ring prints its label instead of handing PHP to Alpine', function () {
    $strategy = Strategy::factory()->create();
    Program::factory()->create(['strategy_id' => $strategy->id]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::strategies.show', ['strategy' => $strategy])
        ->assertSeeHtml('role="img" aria-label="'.e(__('strategies.average_coverage')).': ')
        ->assertDontSeeHtml(':aria-label="__(');
});

test('a page without its own layout gets the Desk frame', function () {
    expect(config('livewire.component_layout'))->toBe('layouts::desk');
});
