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
    'reports' => [fn (): string => route('reports.index')],
    'report' => [fn (): string => route('reports.show', Report::factory()->create())],
    'cities' => [fn (): string => route('admin.cities.index')],
]);

/*
| The money pages (user decision 2026-09-29): Financial, General Manager and
| Super Admin open all of them; Public Relations Manager opens donors,
| proposals and the funding agreements (D-31b), but no other finance page.
*/
dataset('fundraising pages', [
    'proposals' => [fn (): string => route('proposals.index')],
    'proposal' => [fn (): string => route('proposals.show', Proposal::factory()->create())],
    'donors' => [fn (): string => route('donors.index')],
    'donor' => [fn (): string => route('donors.show', Donor::factory()->create())],
]);

dataset('finance pages', [
    'executive summary' => [fn (): string => route('executive-summary.index')],
    'funding agreements' => [fn (): string => route('funding-agreements.index')],
    'funding agreement' => [fn (): string => route('funding-agreements.show', DonorProposal::factory()->create())],
    'transfers' => [fn (): string => route('transfers.index')],
    'accounts' => [fn (): string => route('admin.accounts.index')],
]);

test('an account with no role is refused every module page', function (Closure $url) {
    $this->actingAs(User::factory()->create())->get($url())->assertForbidden();
})->with('module pages');

test('an account with no role is refused the fundraising pages', function (Closure $url) {
    $this->actingAs(User::factory()->create())->get($url())->assertForbidden();
})->with('fundraising pages');

test('an account with no role is refused the finance pages', function (Closure $url) {
    $this->actingAs(User::factory()->create())->get($url())->assertForbidden();
})->with('finance pages');

test('a Team Member can open every module page except the money pages', function (Closure $url) {
    $this->actingAs(userWithRole('Team Member'))->get($url())->assertOk();
})->with('module pages');

test('a Team Member is refused the fundraising pages', function (Closure $url) {
    $this->actingAs(userWithRole('Team Member'))->get($url())->assertForbidden();
})->with('fundraising pages');

test('a Team Member is refused the finance pages', function (Closure $url) {
    $this->actingAs(userWithRole('Team Member'))->get($url())->assertForbidden();
})->with('finance pages');

test('Financial opens every fundraising page', function (Closure $url) {
    $this->actingAs(userWithRole('Financial'))->get($url())->assertOk();
})->with('fundraising pages');

test('Financial opens every finance page', function (Closure $url) {
    $this->actingAs(userWithRole('Financial'))->get($url())->assertOk();
})->with('finance pages');

test('Public Relations Manager opens donors and proposals', function (Closure $url) {
    $this->actingAs(userWithRole('Public Relations Manager'))->get($url())->assertOk();
})->with('fundraising pages');

test('Public Relations Manager opens the funding agreements', function (Closure $url) {
    $this->actingAs(userWithRole('Public Relations Manager'))->get($url())->assertOk();
})->with([
    'funding agreements' => [fn (): string => route('funding-agreements.index')],
    'funding agreement' => [fn (): string => route('funding-agreements.show', DonorProposal::factory()->create())],
]);

test('Public Relations Manager is refused the other finance pages', function (Closure $url) {
    $this->actingAs(userWithRole('Public Relations Manager'))->get($url())->assertForbidden();
})->with([
    'executive summary' => [fn (): string => route('executive-summary.index')],
    'transfers' => [fn (): string => route('transfers.index')],
    'accounts' => [fn (): string => route('admin.accounts.index')],
]);

test('a Team Member opens a task they created', function () {
    $member = userWithRole('Team Member');
    $task = Task::factory()->create(['created_by' => $member->id]);

    $this->actingAs($member)->get(route('tasks.show', $task))->assertOk();
});

test('the dashboard shows no module data to an account with no role', function () {
    $manager = userWithRole('Managers');
    Task::factory()->create(['title' => 'Overdue dashboard task', 'due_date' => now()->subDays(3), 'created_by' => $manager->id]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->assertDontSee('Overdue dashboard task');
    $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertSee('Overdue dashboard task');
});

test('the sidebar hides links an account cannot open', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('admin.accounts.index'));
    $this->actingAs(userWithRole('Team Member'))->get(route('dashboard'))->assertDontSee(route('admin.accounts.index'))->assertSee(route('tasks.index'));
    $this->actingAs(userWithRole('Financial'))->get(route('dashboard'))->assertSee(route('admin.accounts.index'));
});
