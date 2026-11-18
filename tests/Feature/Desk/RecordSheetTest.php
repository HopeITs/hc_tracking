<?php

use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;

test('every record opens as its own full page, not a sheet over its register', function (string $url) {
    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get($url)
        ->assertOk()
        ->assertSee('class="dk-scope"', false)
        ->assertSee('class="dk-back"', false)
        ->assertSee('aria-label="'.__('ui.breadcrumb').'"', false)
        ->assertSee('dk-crumb', false)
        ->assertDontSee('data-desk-record-host', false)
        ->assertDontSee('data-desk-record', false)
        ->assertDontSee('dk-record-sheet', false);
})->with([
    'annual plan' => fn () => route('annual-plans.show', AnnualPlan::factory()->create()),
    'donor report' => fn () => route('donor-reports.show', DonorReport::factory()->create()),
    'donor report compose' => fn () => route('donor-reports.compose'),
    'donor' => fn () => route('donors.show', Donor::factory()->create()),
    'funding agreement' => fn () => route('funding-agreements.show', DonorProposal::factory()->create()),
    'monthly report' => fn () => route('monthly-reports.show', MonthlyReport::factory()->create()),
    'report' => fn () => route('reports.show', Report::factory()->create()),
    'task' => fn () => route('tasks.show', Task::factory()->create()),
    'program' => fn () => route('programs.show', Program::factory()->create()),
]);

test('a program opens as its own page, not a sheet over the projects register', function () {
    $program = Program::factory()->create(['name' => 'Children University']);

    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertSee('class="dk-scope"', false)
        ->assertSee('href="'.route('programs.index').'" wire:navigate', false)
        ->assertSee('Children University')
        ->assertSee('class="dk-story', false)
        ->assertDontSee('data-desk-record-host', false);
});

test('a record the viewer may not open is still refused', function () {
    $task = Task::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('tasks.show', $task))
        ->assertForbidden();
});
