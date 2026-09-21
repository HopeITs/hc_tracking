<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from program detail', function () {
    $program = Program::factory()->create();

    $this->get(route('programs.show', $program))->assertRedirect(route('login'));
});

test('program detail shows the program its strategy and its manager', function () {
    $user = User::factory()->create();
    $manager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad', firstNameAr: 'ليلى', lastNameAr: 'حداد');
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);
    $program = Program::factory()->create([
        'name' => 'Water Security',
        'strategy_id' => $strategy->id,
        'manager_id' => $manager,
    ]);

    $this->actingAs($user)
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertSeeText('Water Security')
        ->assertSeeText('Country Strategy')
        ->assertSeeText('Layla Haddad')
        ->assertSeeText('ليلى حداد');
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
    AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Improved access to safe water.',
        'type' => ResultType::Impact,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('Annual plan 2026')
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

test('program logframe counts statements per level and flags unmeasured ones', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $impact = Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Impact]);
    $outcome = Result::factory()->create(['program_id' => $program->id, 'parent_id' => $impact->id, 'type' => ResultType::Outcome]);
    $output = Result::factory()->create(['program_id' => $program->id, 'parent_id' => $outcome->id, 'type' => ResultType::Output]);
    Indicator::factory()->count(2)->create(['result_id' => $output->id]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'results')
        ->assertSet('resultSummary', [
            'levels' => ['impact' => 1, 'outcome' => 1, 'output' => 1],
            'indicators' => 2,
            'unmeasured' => 2,
        ])
        ->assertSee('data-tree-depth="2"', false)
        ->assertSee('2 indicators')
        ->assertSee('2 statements without indicators');
});

test('program plan timeline fills the strategy window and totals each plan', function () {
    $user = User::factory()->create();
    $strategy = Strategy::factory()->create(['start_year' => 2025, 'end_year' => 2027]);
    $program = Program::factory()->create(['strategy_id' => $strategy->id]);
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $impact = Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Impact]);
    $target = PlanIndicator::factory()->create([
        'source_id' => $plan->id,
        'indicator_id' => Indicator::factory()->create(['result_id' => $impact->id])->id,
    ]);
    Report::factory()->approved()->create(['plan_indicator_id' => $target->id]);
    Report::factory()->create(['plan_indicator_id' => $target->id]);

    $timeline = Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('No plan written for this year')
        ->instance()
        ->planTimeline;

    expect(array_column($timeline, 'year'))->toBe([2025, 2026, 2027])
        ->and($timeline[0]['plan'])->toBeNull()
        ->and($timeline[1])->toMatchArray([
            'targets' => 1,
            'attributed' => 0,
            'levels' => ['impact' => 1, 'outcome' => 0, 'output' => 0],
            'reports' => 2,
            'approved' => 1,
        ]);
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

test('program detail calculates milestone progress without cancelled milestones', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id]);

    Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::Reached,
        'weight_percentage' => '40.00',
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::Planned,
        'weight_percentage' => '60.00',
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::Cancelled,
        'weight_percentage' => '100.00',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('40%')
        ->assertSee('40 of 100 weighted points reached');
});

test('program detail narrows projects with its delivery search', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Water Network Expansion']);
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Shelter Rehabilitation']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('projectSearch', 'Water')
        ->assertSee('Water Network Expansion')
        ->assertDontSee('Shelter Rehabilitation');
});

test('program delivery timeline filters projects by status', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Water Network Expansion', 'status' => ProjectStatus::Active]);
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Shelter Rehabilitation', 'status' => ProjectStatus::Closed]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('projectStatus', 'closed')
        ->assertSee('Shelter Rehabilitation')
        ->assertDontSee('Water Network Expansion')
        ->set('projectStatus', 'not-a-status')
        ->assertSet('projectStatus', '')
        ->assertSee('Water Network Expansion');
});

test('program delivery timeline shows weighted progress per project', function () {
    $this->travelTo('2026-06-01');

    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'program_id' => $program->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Reached, 'weight_percentage' => '25.00', 'planned_start_date' => '2026-03-01']);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Planned, 'weight_percentage' => '75.00', 'planned_start_date' => '2026-10-01']);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Cancelled, 'weight_percentage' => '50.00', 'planned_start_date' => '2020-01-01']);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('25%')
        ->assertSee('1 of 2 milestones reached');

    expect($component->instance()->deliveryWindow['start']->toDateString())->toBe('2026-01-01');
});

test('program delivery timeline flags schedule slippage and milestones outside the project dates', function () {
    $this->travelTo('2026-07-02');

    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'program_id' => $program->id,
        'status' => ProjectStatus::Active,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Reached, 'weight_percentage' => '25.00', 'planned_start_date' => '2026-03-01']);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Planned, 'weight_percentage' => '75.00', 'planned_start_date' => '2027-05-01']);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('Behind schedule')
        ->assertSee('50% expected by today')
        ->assertSee('1 milestone outside the delivery window');

    expect($component->instance()->deliveryWindow['end']->toDateString())->toBe('2027-05-31');
});

test('program delivery timeline marks open projects past their end date', function () {
    $this->travelTo('2026-09-15');

    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'program_id' => $program->id,
        'status' => ProjectStatus::Active,
        'start_date' => '2025-01-01',
        'end_date' => '2026-06-30',
    ]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Planned, 'weight_percentage' => '100.00', 'planned_start_date' => '2026-05-01']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('Past end date')
        ->assertDontSee('outside the delivery window');
});

test('program delivery timeline ignores an unknown status from the url', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Water Network Expansion']);

    Livewire::withQueryParams(['projectStatus' => 'not-a-status'])
        ->actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSet('projectStatus', '')
        ->assertSee('Water Network Expansion');
});

test('program delivery timeline status buttons filter and clear the list', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Water Network Expansion', 'status' => ProjectStatus::Active]);
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Shelter Rehabilitation', 'status' => ProjectStatus::Closed]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->call('filterProjectsByStatus', 'active')
        ->assertSet('projectStatus', 'active')
        ->assertDontSee('Shelter Rehabilitation')
        ->call('filterProjectsByStatus', 'archived')
        ->assertSee('No projects match these filters')
        ->call('filterProjectsByStatus', 'bogus')
        ->assertSet('projectStatus', '')
        ->assertSee('Shelter Rehabilitation');
});

test('program delivery search treats wildcard characters literally', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'name' => 'Water Network Expansion']);
    Project::factory()->create(['program_id' => $program->id, 'name' => '100% Coverage Drive']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('projectSearch', '%')
        ->assertSee('100% Coverage Drive')
        ->assertDontSee('Water Network Expansion');
});

test('program delivery timeline does not rate projects that have not started delivery', function () {
    $this->travelTo('2026-07-02');

    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'program_id' => $program->id,
        'status' => ProjectStatus::Approved,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Planned, 'weight_percentage' => '100.00', 'planned_start_date' => '2026-06-01']);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('Not started')
        ->assertDontSee('Behind schedule')
        ->assertDontSee('expected by today');
});

test('program delivery timeline marks a finished window as past end even without milestones', function () {
    $this->travelTo('2026-09-15');

    $program = Program::factory()->create();
    Project::factory()->create([
        'program_id' => $program->id,
        'status' => ProjectStatus::Active,
        'start_date' => '2025-01-01',
        'end_date' => '2026-03-31',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('Past end date');
});

test('program delivery window is not stretched by a mistyped milestone date', function () {
    $this->travelTo('2026-06-01');

    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'program_id' => $program->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Planned, 'weight_percentage' => '100.00', 'planned_start_date' => '2035-01-01']);

    $window = Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('1 milestone outside the delivery window')
        ->instance()
        ->deliveryWindow;

    expect($window['end']->toDateString())->toBe('2026-12-31');
});

test('program detail links each annual plan year to its plan', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee(route('annual-plans.show', $plan), false);
});
