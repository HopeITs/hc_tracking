<?php

use App\Enums\DonorAttribution;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from program detail', function () {
    $program = Program::factory()->create();

    $this->get(route('programs.show', $program))->assertRedirect(route('login'));
});

test('program detail shows the program its strategy and its manager', function () {
    $user = User::factory()->role('General Manager')->create();
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
        ->assertSeeText('ليلى حداد')
        ->assertSee('data-program-manager-photo', false);
});

test('program detail shows no manager photo when no manager is assigned', function () {
    $program = Program::factory()->create(['manager_id' => null]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.show', $program))
        ->assertOk()
        ->assertDontSee('data-program-manager-photo', false);
});

test('the program breadcrumb leads back through the strategies list and the program strategy', function () {
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);
    $program = Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $strategy->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.show', $program))
        ->assertSee('<a href="'.route('strategies.index').'" wire:navigate.hover>'.__('common.strategies').'</a>', false)
        ->assertSee('<a href="'.route('strategies.show', $strategy).'" wire:navigate.hover>Country Strategy</a>', false)
        ->assertSee('<span aria-current="page">Water Security</span>', false);
});

test('the program back link returns to the strategy page it was opened from', function () {
    $strategy = Strategy::factory()->create();
    $program = Program::factory()->create(['strategy_id' => $strategy->id]);

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.show', $program), ['referer' => route('strategies.show', $strategy)])
        ->assertSee('class="dk-back" href="'.route('strategies.show', $strategy).'" wire:navigate', false);
});

test('program detail lists only its own projects', function () {
    $user = User::factory()->role('General Manager')->create();
    $subject = Program::factory()->create();
    $other = Program::factory()->create();

    Project::factory()->create(['name' => 'Aleppo Wells', 'program_id' => $subject->id]);
    Project::factory()->create(['name' => 'Homs Shelter', 'program_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $subject])
        ->assertSet('tab', 'overview')
        ->assertSee('Aleppo Wells')
        ->assertDontSee('Homs Shelter');
});

test('program detail switches to annual plans and results', function () {
    $user = User::factory()->role('General Manager')->create();
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

test('program detail tasks tab shows tasks raised on the program, its projects and their milestones, but not on another program', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id]);
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $otherProgram = Program::factory()->create();

    Task::factory()->create(['source_type' => 'program', 'source_id' => $program->id, 'title' => 'Approve the annual workplan']);
    Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'title' => 'Sign the site access agreement']);
    Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Deliver the milestone report']);
    Task::factory()->create(['source_type' => 'program', 'source_id' => $otherProgram->id, 'title' => 'Unrelated program task']);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSee('Approve the annual workplan')
        ->assertSee('Sign the site access agreement')
        ->assertSee('Deliver the milestone report')
        ->assertDontSee('Unrelated program task');
});

test('a task can be added from the program show page pre-filled with this program', function () {
    $user = User::factory()->role('General Manager')->create();
    $assignee = insertHrEmployee();
    $program = Program::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->call('openAddTaskModal')
        ->assertSet('showAddTaskModal', true)
        ->set('newTaskTitle', '  Approve the workplan  ')
        ->set('newTaskAssignedTo', [(string) $assignee])
        ->call('addTask')
        ->assertHasNoErrors()
        ->assertSet('showAddTaskModal', false);

    $task = Task::query()->where('title', 'Approve the workplan')->sole();

    expect($task->source_type)->toBe('program')
        ->and($task->source_id)->toBe($program->id)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$assignee]);
});

test('adding a task from the program show page requires a title but no assignee', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->call('openAddTaskModal')
        ->set('newTaskTitle', '   ')
        ->set('newTaskAssignedTo', [])
        ->call('addTask')
        ->assertHasErrors(['newTaskTitle'])
        ->assertHasNoErrors(['newTaskAssignedTo']);
});

test('program detail renders the results logframe as a tree', function () {
    $user = User::factory()->role('General Manager')->create();
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

test('program detail shows only the program framework, never a proposal framework of the same program', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Program impact statement.']);
    $proposalImpact = Result::factory()->type(ResultType::Impact)->create([
        'program_id' => $program->id,
        'proposal_id' => Proposal::factory()->create()->id,
        'statement' => 'Proposal impact statement.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'results')
        ->assertSee('Program impact statement.')
        ->assertDontSee('Proposal impact statement.');

    expect($program->results()->pluck('id')->all())->not->toContain($proposalImpact->id);
});

test('program logframe counts statements per level and flags unmeasured ones', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $impact = Result::factory()->create(['program_id' => $program->id, 'type' => ResultType::Impact]);
    $outcome = Result::factory()->create(['program_id' => $program->id, 'parent_id' => $impact->id, 'type' => ResultType::Outcome]);
    $output = Result::factory()->create(['program_id' => $program->id, 'parent_id' => $outcome->id, 'type' => ResultType::Output]);
    Indicator::factory()->count(2)->create(['result_id' => $output->id]);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'results')
        ->assertSet('resultSummary', [
            'levels' => ['impact' => 1, 'outcome' => 1, 'output' => 1, 'activity' => 0, 'cross-cutting' => 0],
            'indicators' => 2,
            'unmeasured' => 2,
        ])
        ->assertSee('data-tree-depth="2"', false)
        ->assertSee('2 indicators')
        ->assertSee('2 statements without indicators');
});

test('program plan years fill the strategy window and total each plan', function () {
    $user = User::factory()->role('General Manager')->create();
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

    $years = Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee('No plan')
        ->instance()
        ->planYears;

    expect(array_column($years, 'year'))->toBe([2025, 2026, 2027])
        ->and($years[0]['plan'])->toBeNull()
        ->and($years[1])->toMatchArray([
            'targets' => 1,
            'attributed' => 0,
            'levels' => ['impact' => 1, 'outcome' => 0, 'output' => 0, 'activity' => 0, 'cross-cutting' => 0],
            'reports' => 2,
            'approved' => 1,
        ]);
});

test('program detail renders a program with nothing attached', function () {
    $user = User::factory()->role('General Manager')->create();
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

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('40%')
        ->assertSee('40 of 100 progress points reached');
});

test('program detail links each annual plan year to its plan', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'plans')
        ->assertSee(route('annual-plans.show', $plan), false);
});

test('MEAL sets the program donor attribution and it is logged', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $program = Program::factory()->create();

    Livewire::actingAs(User::factory()->role('MEAL')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeText(__('programs.donor_attribution'))
        ->call('setDonorAttribution', 'split')
        ->assertHasNoErrors();

    expect(DB::table('programs')->where('id', $program->id)->value('donor_attribution'))->toBe('split')
        ->and(DB::table('activities')->where('subject_type', 'program')->where('subject_id', $program->id)->where('event', 'program.updated')->exists())->toBeTrue();
});

test('only report-templates.manage holders set the donor attribution', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $program = Program::factory()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeText(DonorAttribution::Shared->label())
        ->call('setDonorAttribution', 'split')
        ->assertForbidden();

    expect(DB::table('programs')->where('id', $program->id)->value('donor_attribution'))->toBe('shared');
});

test('program results chain lists each contributing proposal under the program result it contributes to', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();
    $otherProgram = Program::factory()->create();
    $impact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id, 'statement' => 'Program impact statement.']);
    $otherImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $otherProgram->id, 'statement' => 'Other program impact.']);
    $proposal = Proposal::factory()->create(['name' => 'Multi-program proposal']);
    $rootHere = Result::factory()->type(ResultType::Impact)->create(['proposal_id' => $proposal->id, 'program_id' => null, 'statement' => 'Proposal root for this program.']);
    $rootHere->forceFill(['contributes_to_result_id' => $impact->id])->saveQuietly();
    $rootThere = Result::factory()->type(ResultType::Impact)->create(['proposal_id' => $proposal->id, 'program_id' => null, 'statement' => 'Proposal root for the other program.']);
    $rootThere->forceFill(['contributes_to_result_id' => $otherImpact->id])->saveQuietly();

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'results')
        ->assertSeeHtml('data-chain-contributions')
        ->assertSeeInOrder(['Program impact statement.', 'Multi-program proposal', $proposal->donor->name, 'Proposal root for this program.'])
        ->assertDontSee('Proposal root for the other program.');

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $otherProgram])
        ->set('tab', 'results')
        ->assertSee('Multi-program proposal')
        ->assertSee('Proposal root for the other program.')
        ->assertDontSee('Proposal root for this program.');
});

test('the program overview ranks the viewer\'s own past-due tasks first and calls the program at risk', function () {
    $employeeId = insertHrEmployee();
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'name' => 'Wells Repair']);
    $mine = Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'due_date' => now()->subDays(3)]);
    $mine->taskAssignees()->delete();
    $mine->taskAssignees()->create(['employee_id' => $employeeId]);
    Task::factory()->create(['source_type' => 'program', 'source_id' => $program->id, 'due_date' => now()->subDay()]);
    Task::factory()->completed()->create(['source_type' => 'program', 'source_id' => $program->id, 'due_date' => now()->subDay()]);
    Task::factory()->create(['source_type' => 'program', 'source_id' => $program->id, 'due_date' => now()->addWeek()]);

    $page = Livewire::actingAs(userWithRole('General Manager', $employeeId))
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeHtml('data-program-verdict="at_risk"')
        ->assertSee('You have 1 task past its due date')
        ->assertSee('1 more task is past due for others');

    expect(array_column($page->instance()->attention, 'key'))->toBe(['my_tasks', 'tasks'])
        ->and($page->instance()->healthFigures['pastDueTasks'])->toBe(2);
});

test('the projects grid lists each project once behind filter chips that count them', function () {
    $program = Program::factory()->create();
    $late = Project::factory()->create([
        'program_id' => $program->id, 'name' => 'Late Wells', 'status' => ProjectStatus::Active,
        'start_date' => now()->subYear(), 'end_date' => now()->subMonth(),
    ]);
    $fine = Project::factory()->create([
        'program_id' => $program->id, 'name' => 'Fresh Start', 'status' => ProjectStatus::Active,
        'start_date' => now()->addMonth(), 'end_date' => now()->addYear(),
    ]);

    $page = Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::programs.show', ['program' => $program]);
    $html = $page->html();

    expect(substr_count($html, 'wire:key="program-card-'.$late->id.'"'))->toBe(1)
        ->and(substr_count($html, 'wire:key="program-card-'.$fine->id.'"'))->toBe(1)
        ->and($html)->toContain('data-ledger-filter="all"')
        ->and($html)->toContain('data-ledger-filter="behind"')
        ->and($html)->toContain('data-ledger-filter="on_track"')
        ->and($html)->not->toContain('data-ledger-filter="closed"')
        ->and($html)->not->toContain('data-ledger-group');

    $page->assertSeeInOrder(['Late Wells', 'Fresh Start'])
        ->assertSeeHtml('href="'.route('projects.show', $late).'"')
        ->assertSeeHtml('data-ledger-city')
        ->assertSeeHtml('data-ledger-manager-photo')
        ->assertDontSee('Targets and funding')
        ->assertSeeHtml('data-test="program-ledger-more"')
        ->assertSee('Load 1 project');
});

test('the attention list shows three items first and loads more three at a time', function () {
    $program = Program::factory()->create();
    Project::factory()->count(5)->create([
        'program_id' => $program->id,
        'status' => ProjectStatus::Active,
        'start_date' => now()->subYear(),
        'end_date' => now()->subMonth(),
    ]);

    $page = Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::programs.show', ['program' => $program]);
    $total = count($page->instance()->attention);

    expect($total)->toBeGreaterThan(3);
    $page->assertSeeHtml("shown: 3, total: {$total}, step: 3")
        ->assertDontSeeHtml('x-show="shown > 2"')
        ->assertSeeHtml('x-show="shown > 3"')
        ->assertSeeHtml('data-test="program-attention-more"')
        ->assertSee('Load 3 more');
});

test('the program overview flags late milestones and a missing monthly report for its project', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'name' => 'Shelter Fix', 'status' => ProjectStatus::Active]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Ongoing, 'planned_end_date' => now()->subWeek()]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Reached, 'planned_end_date' => now()->subWeek()]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('Shelter Fix has 1 late milestone')
        ->assertSee(route('projects.show', ['project' => $project, 'tab' => 'milestones']), false)
        ->assertSee('1 active project has no '.now()->subMonthNoOverflow()->translatedFormat('F').' report yet')
        ->assertSeeHtml('data-ledger-report');
});

test('the program overview calls a program with nothing late on track', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Closed]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeHtml('data-program-verdict="on_track"')
        ->assertSee('All clear: nothing is late, missing or slipping.');
});

test('the program overview shows money only to holders of both money permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertDontSeeHtml('data-program-funding');

    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeHtml('data-program-funding');
});

test('old timeline tab links and unknown tabs fall back to the overview', function () {
    $program = Program::factory()->create();
    $user = User::factory()->role('General Manager')->create();

    foreach (['projects', 'timeline'] as $oldTab) {
        Livewire::withQueryParams(['tab' => $oldTab])->actingAs($user)
            ->test('pages::programs.show', ['program' => $program])
            ->assertSet('tab', 'overview')
            ->assertDontSeeHtml('data-test="program-tab-timeline"');
    }

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'bogus')
        ->assertSet('tab', 'overview');
});

test('the program overview totals money per project and counts an agreement shared by two projects once', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $program = Program::factory()->create();
    $first = Project::factory()->create(['program_id' => $program->id]);
    $second = Project::factory()->create(['program_id' => $program->id]);
    $proposal = Proposal::factory()->create();
    $proposal->projects()->attach([$first->id, $second->id]);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '1000.00', 'currency' => 'USD']);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'net_amount_received' => '400.00']);

    $page = Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::programs.show', ['program' => $program]);

    expect($page->instance()->programFunding)->toBe([['currency' => 'USD', 'committed' => 1000.0, 'received' => 400.0]])
        ->and(collect($page->instance()->projectLedger)->pluck('funding')->all())
        ->each->toBe([['currency' => 'USD', 'committed' => 1000.0, 'received' => 400.0]]);
});

test('a project needs attention only once its past-due tasks reach the threshold', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Closed]);
    $pastDue = fn () => Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'due_date' => now()->subDays(2)]);
    $needsAttention = fn (): bool => Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->instance()->projectLedger[0]['needsAttention'];

    foreach (range(1, 2) as $i) {
        $pastDue();
    }
    expect($needsAttention())->toBeFalse();

    $pastDue();
    expect($needsAttention())->toBeTrue();
});

test('the tasks tab lists each project beside the picked project tasks, the late ones first', function () {
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'name' => 'Late Project']);
    $other = Project::factory()->create(['program_id' => $program->id, 'name' => 'Calm Project']);
    Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'title' => 'Late one', 'due_date' => now()->subDay()]);
    Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'title' => 'Soon one', 'due_date' => now()->addWeek()]);
    Task::factory()->create(['source_type' => 'project', 'source_id' => $other->id, 'title' => 'Calm one', 'due_date' => now()->addWeek()]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->set('tab', 'tasks')
        ->assertSeeHtmlInOrder(['data-task-bucket="all"', 'data-task-bucket="project-'.$project->id.'"', 'data-task-bucket="project-'.$other->id.'"'])
        ->assertSeeHtml('data-task-pane="all"')
        ->assertSee(['Late one', 'Soon one', 'Calm one'])
        ->call('pickTaskBucket', 'project-'.$other->id)
        ->assertSet('taskBucket', 'project-'.$other->id)
        ->assertSeeHtml('data-task-pane="project-'.$other->id.'"')
        ->assertSee('Calm one')
        ->assertDontSee('Late one')
        ->call('pickTaskBucket', 'drop table')
        ->assertSet('taskBucket', 'all');
});

test('a late transfer is listed for attention but does not flag the project card', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $program = Program::factory()->create();
    $project = Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Closed]);
    $proposal = Proposal::factory()->create();
    $proposal->projects()->attach($project->id);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'currency' => 'USD']);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'scheduled_date' => now()->subMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => 'pending',
    ]);

    $page = Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::programs.show', ['program' => $program]);

    expect(array_column($page->instance()->attention, 'key'))->toContain('transfers')
        ->and($page->instance()->projectLedger[0]['needsAttention'])->toBeFalse();
});
