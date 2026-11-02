<?php

use App\Models\User;
use Livewire\Livewire;

test('every register opens in the desk frame with a way back to the desk', function (string $route) {
    $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route($route))
        ->assertOk()
        ->assertSee('class="dk-scope"', false)
        ->assertSee('data-desk-register', false)
        ->assertSee('href="'.route('dashboard').'"', false)
        ->assertDontSee('<header data-page-header', false);
})->with([
    'tasks.index', 'projects.index', 'proposals.index', 'transfers.index', 'donors.index',
    'funding-agreements.index', 'milestones.index', 'annual-plans.index', 'risks.index',
    'reports.index', 'monthly-reports.index', 'reporting-duties.index', 'reviews.index',
    'activity.index', 'calendar.index', 'department-tasks.index', 'targets.index', 'notifications.index',
    'timeline.index', 'results.index', 'reports.monthly-matrix', 'indicator-library.index',
]);

test('the register view switch writes the page view', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::tasks.index')
        ->assertSeeHtml('data-desk-register-view="calendar"')
        ->assertSeeHtml('aria-pressed="true" data-desk-register-view="board"')
        ->set('view', 'calendar')
        ->assertSeeHtml('aria-pressed="true" data-desk-register-view="calendar"');
});

test('the task due filter replaces the old quick-filter tiles', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::tasks.index')
        ->set('dueFilter', 'overdue')
        ->assertSeeHtml('data-test="task-clear-filters"')
        ->call('clearFilters')
        ->assertSet('dueFilter', '')
        ->assertDontSeeHtml('data-test="task-clear-filters"');
});

test('the page tour points at the register title, bar and actions first', function () {
    $html = $this->actingAs(User::factory()->role('Super Admin')->create())
        ->get(route('risks.index'))
        ->getContent();

    expect($html)->toContain('[data-desk-register] .dk-reg-h h1')
        ->and($html)->toContain('[data-desk-register-bar]')
        ->and($html)->toContain('[data-desk-register-actions]');
});
