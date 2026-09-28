<?php

use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;

dataset('module pages', [
    'executive summary' => [fn (): string => route('executive-summary.index')],
    'calendar' => [fn (): string => route('calendar.index')],
    'strategies' => [fn (): string => route('strategies.index')],
    'strategy' => [fn (): string => route('strategies.show', Strategy::factory()->create())],
    'programs' => [fn (): string => route('programs.index')],
    'program' => [fn (): string => route('programs.show', Program::factory()->create())],
    'annual plans' => [fn (): string => route('annual-plans.index')],
    'annual plan' => [fn (): string => route('annual-plans.show', AnnualPlan::factory()->create())],
    'projects' => [fn (): string => route('projects.index')],
    'project' => [fn (): string => route('projects.show', Project::factory()->create())],
    'timeline' => [fn (): string => route('timeline.index')],
    'risks' => [fn (): string => route('risks.index')],
    'milestones' => [fn (): string => route('milestones.index')],
    'milestone' => [fn (): string => route('milestones.show', Milestone::factory()->create())],
    'results' => [fn (): string => route('results.index')],
    'indicators' => [fn (): string => route('indicators.index')],
    'targets' => [fn (): string => route('targets.index')],
    'tasks' => [fn (): string => route('tasks.index')],
    'proposals' => [fn (): string => route('proposals.index')],
    'proposal' => [fn (): string => route('proposals.show', Proposal::factory()->create())],
    'donors' => [fn (): string => route('donors.index')],
    'donor' => [fn (): string => route('donors.show', Donor::factory()->create())],
    'funding agreements' => [fn (): string => route('funding-agreements.index')],
    'funding agreement' => [fn (): string => route('funding-agreements.show', DonorProposal::factory()->create())],
    'transfers' => [fn (): string => route('transfers.index')],
    'reports' => [fn (): string => route('reports.index')],
    'report' => [fn (): string => route('reports.show', Report::factory()->create())],
    'cities' => [fn (): string => route('admin.cities.index')],
    'accounts' => [fn (): string => route('admin.accounts.index')],
]);

test('an account with no role is refused every module page', function (Closure $url) {
    $this->actingAs(User::factory()->create())->get($url())->assertForbidden();
})->with('module pages');

test('a Team Member can open every module page', function (Closure $url) {
    $this->actingAs(userWithRole('Team Member'))->get($url())->assertOk();
})->with('module pages');

test('a Team Member opens a task they created', function () {
    $member = userWithRole('Team Member');
    $task = Task::factory()->create(['created_by' => $member->id]);

    $this->actingAs($member)->get(route('tasks.show', $task))->assertOk();
});

test('the dashboard shows no module data to an account with no role', function () {
    Task::factory()->create(['title' => 'Overdue dashboard task', 'due_date' => now()->subDays(3)]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->assertDontSee('Overdue dashboard task');
    $this->actingAs(userWithRole('Managers'))->get(route('dashboard'))->assertOk()->assertSee('Overdue dashboard task');
});

test('the sidebar hides links an account cannot open', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('admin.accounts.index'));
    $this->actingAs(userWithRole('Team Member'))->get(route('dashboard'))->assertSee(route('admin.accounts.index'));
});
