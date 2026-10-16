<?php

use App\Enums\MilestoneStatus;
use App\Enums\ResultType;
use App\Enums\TaskStatus;
use App\Models\ActivitySchedule;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array<string, array<string, mixed>>
 */
function ganttRowsFor(Project $project): array
{
    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'plan');

    return collect($component->instance()->ganttPayload['rows'])->keyBy('id')->all();
}

test('the plan tab renders the gantt chart', function () {
    $project = Project::factory()->active()->create();
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Baseline assessment', 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-20']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'plan')
        ->assertSeeHtml('data-gantt')
        ->assertSee('Baseline assessment');
});

test('the plan tab shows an empty state when there is nothing to plan', function () {
    $project = Project::factory()->active()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'plan')
        ->assertDontSeeHtml('data-gantt')
        ->assertSee(__('gantt.nothing_to_plan'));
});

test('milestones nest tasks and subtasks with WBS numbers and roll their span up', function () {
    $project = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $project->id, 'sequence' => 1, 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-10']);
    $child = Milestone::factory()->childOf($parent)->create(['sequence' => 1, 'planned_start_date' => '2026-10-05', 'planned_end_date' => '2026-11-30']);
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $parent->id, 'start_date' => '2026-09-20', 'due_date' => '2026-10-02', 'status' => TaskStatus::Done]);
    $subtask = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $parent->id, 'parent_task_id' => $task->id, 'start_date' => '2026-09-21', 'due_date' => '2026-09-25']);

    $rows = ganttRowsFor($project);

    expect($rows['delivery']['wbs'])->toBe('1')
        ->and($rows['milestone-'.$parent->id])->toMatchArray(['wbs' => '1.1', 'depth' => 1, 'summary' => true, 'start' => '2026-09-20', 'end' => '2026-11-30'])
        ->and($rows['task-'.$task->id])->toMatchArray(['wbs' => '1.1.1', 'parent' => 'milestone-'.$parent->id, 'progress' => 100])
        ->and($rows['task-'.$subtask->id])->toMatchArray(['wbs' => '1.1.1.1', 'depth' => 3])
        ->and($rows['milestone-'.$child->id])->toMatchArray(['wbs' => '1.1.2', 'depth' => 2, 'summary' => false]);
});

test('a single-day milestone without children is drawn as a diamond and missed ones read as critical', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2020-01-10', 'planned_end_date' => '2020-01-10']);

    $rows = ganttRowsFor($project);

    expect($rows['milestone-'.$milestone->id])->toMatchArray(['diamond' => true, 'tone' => 'critical', 'start' => '2020-01-10', 'end' => '2020-01-10']);
});

test('linked proposals form the fundraising phase and their donor reports appear as obligations', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['planned_date_begin' => '2026-06-01', 'planned_date_end' => '2026-08-31']);
    $project->proposals()->attach($proposal);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim narrative', 'due_on' => '2027-01-15', 'submitted_on' => null]);

    $rows = ganttRowsFor($project);

    expect($rows['fundraising'])->toMatchArray(['start' => '2026-06-01', 'end' => '2026-08-31'])
        ->and($rows['proposal-'.$proposal->id])->toMatchArray(['kind' => 'proposal', 'tone' => 'funding'])
        ->and($rows['report-'.$report->id])->toMatchArray(['kind' => 'report', 'diamond' => true, 'start' => '2027-01-15', 'parent' => 'obligations']);
});

test('tasks raised against another project never leak into this plan', function () {
    $project = Project::factory()->create();
    Milestone::factory()->create(['project_id' => $project->id]);
    $foreign = Task::factory()->create(['source_type' => 'milestone', 'source_id' => Milestone::factory()->create()->id]);

    expect(ganttRowsFor($project))->not->toHaveKey('task-'.$foreign->id);
});

/**
 * @return Livewire\Features\SupportTesting\Testable
 */
function projectPage(Project $project)
{
    return Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::projects.show', ['project' => $project]);
}

test('dragging a milestone bar reschedules its planned dates', function () {
    $milestone = Milestone::factory()->create(['planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-10']);

    projectPage($milestone->project)->call('ganttReschedule', 'milestone-'.$milestone->id, '2026-10-05', '2026-10-20');

    expect($milestone->fresh())
        ->planned_start_date->toDateString()->toBe('2026-10-05')
        ->planned_end_date->toDateString()->toBe('2026-10-20');
});

test('rescheduling a task writes its start and due dates', function () {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'start_date' => '2026-10-01', 'due_date' => '2026-10-03']);

    projectPage($milestone->project)->call('ganttReschedule', 'task-'.$task->id, '2026-11-01', '2026-11-04');

    expect($task->fresh())
        ->start_date->toDateString()->toBe('2026-11-01')
        ->due_date->toDateString()->toBe('2026-11-04');
});

test('a finish before the start is refused and nothing changes', function () {
    $milestone = Milestone::factory()->create(['planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-10']);

    projectPage($milestone->project)->call('ganttReschedule', 'milestone-'.$milestone->id, '2026-10-20', '2026-10-05');

    expect($milestone->fresh()->planned_start_date->toDateString())->toBe('2026-10-01');
});

test('rows of another project cannot be edited through this page', function () {
    $project = Project::factory()->create();
    $foreign = Milestone::factory()->create(['name' => 'Foreign', 'planned_start_date' => '2026-10-01']);

    projectPage($project)
        ->call('ganttRename', 'milestone-'.$foreign->id, 'Hijacked')
        ->call('ganttReschedule', 'milestone-'.$foreign->id, '2027-01-01', '2027-01-02');

    expect($foreign->fresh())
        ->name->toBe('Foreign')
        ->planned_start_date->toDateString()->toBe('2026-10-01');
});

test('task progress and names are edited inline', function () {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'progress_percentage' => 0]);

    projectPage($milestone->project)
        ->call('ganttSetProgress', 'task-'.$task->id, 60)
        ->call('ganttRename', 'milestone-'.$milestone->id, '  Works handover  ');

    expect($task->fresh()->progress_percentage)->toBe(60)
        ->and($milestone->fresh()->name)->toBe('Works handover');
});

test('progress outside 0 to 100 and progress on milestones are refused', function () {
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'progress_percentage' => 10]);

    projectPage($milestone->project)
        ->call('ganttSetProgress', 'task-'.$task->id, 140)
        ->call('ganttSetProgress', 'milestone-'.$milestone->id, 50);

    expect($task->fresh()->progress_percentage)->toBe(10);
});

test('indenting a task nests it under a sibling task and outdenting lifts it back', function () {
    $milestone = Milestone::factory()->create();
    $first = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    $second = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    $page = projectPage($milestone->project);

    $page->call('ganttSetParent', 'task-'.$second->id, 'task-'.$first->id);
    expect($second->fresh()->parent_task_id)->toBe($first->id);

    $page->call('ganttSetParent', 'task-'.$second->id, null);
    expect($second->fresh()->parent_task_id)->toBeNull();
});

test('a task cannot be nested under a subtask', function () {
    $milestone = Milestone::factory()->create();
    $parent = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);
    $subtask = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'parent_task_id' => $parent->id]);
    $loose = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);

    projectPage($milestone->project)->call('ganttSetParent', 'task-'.$loose->id, 'task-'.$subtask->id);

    expect($loose->fresh()->parent_task_id)->toBeNull();
});

test('milestones indent under a sibling and a cycle is refused', function () {
    $project = Project::factory()->create();
    $first = Milestone::factory()->create(['project_id' => $project->id]);
    $second = Milestone::factory()->create(['project_id' => $project->id]);
    $page = projectPage($project);

    $page->call('ganttSetParent', 'milestone-'.$second->id, 'milestone-'.$first->id);
    expect($second->fresh()->parent_milestone_id)->toBe($first->id);

    $page->call('ganttSetParent', 'milestone-'.$first->id, 'milestone-'.$second->id);
    expect($first->fresh()->parent_milestone_id)->toBeNull();
});

test('an edit answers with the rebuilt payload', function () {
    $milestone = Milestone::factory()->create(['planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-10']);

    $result = projectPage($milestone->project)
        ->instance()
        ->ganttReschedule('milestone-'.$milestone->id, '2026-12-01', '2026-12-02');

    expect($result['ok'])->toBeTrue()
        ->and(collect($result['payload']['rows'])->firstWhere('id', 'milestone-'.$milestone->id))
        ->toMatchArray(['start' => '2026-12-01', 'end' => '2026-12-02', 'editable' => true]);
});

/**
 * A project funded by two proposals, each with a plan: a shared milestone with
 * a plan task, this center's own milestone, and another center's milestone.
 *
 * @return array{project: Project, first: Proposal, second: Proposal, shared: Milestone, own: Milestone, elsewhere: Milestone, task: Task}
 */
function ganttPlanFixture(): array
{
    $project = Project::factory()->active()->create();
    $otherCenter = Project::factory()->create();
    $first = Proposal::factory()->create(['name' => 'Winter Response', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    $second = Proposal::factory()->create(['name' => 'Summer Schools', 'planned_date_begin' => '2026-03-01', 'planned_date_end' => '2026-12-31']);
    $project->proposals()->attach([$first->id, $second->id]);
    $otherCenter->proposals()->attach($first->id);

    $plan = fn (Proposal $proposal, ?Project $center, string $name): Milestone => Milestone::factory()->create([
        'proposal_id' => $proposal->id,
        'project_id' => $center?->id,
        'name' => $name,
        'planned_start_date' => '2026-04-01',
        'planned_end_date' => '2026-04-30',
    ]);

    $shared = $plan($first, null, 'Shared consultations');
    $own = $plan($first, $project, 'Our center launch');
    $elsewhere = $plan($first, $otherCenter, 'Other center launch');
    $plan($second, null, 'School kits');
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $shared->id, 'department_id' => insertHrDepartment('Media'), 'title' => 'Film the consultations', 'start_date' => '2026-04-02', 'due_date' => '2026-04-10']);

    return compact('project', 'first', 'second', 'shared', 'own', 'elsewhere', 'task');
}

test('each linked proposal\'s plan is drawn as its own read-only group with its own colour', function () {
    $fixture = ganttPlanFixture();

    $component = projectPage($fixture['project'])->set('tab', 'plan');
    $payload = $component->instance()->ganttPayload;
    $rows = collect($payload['rows'])->keyBy('id');

    expect($payload['series'])->toBe([
        ['series' => 1, 'id' => $fixture['first']->id, 'name' => 'Winter Response'],
        ['series' => 2, 'id' => $fixture['second']->id, 'name' => 'Summer Schools'],
    ])
        ->and($rows['plan-'.$fixture['first']->id])->toMatchArray(['kind' => 'group', 'series' => 1])
        ->and($rows['plan-milestone-'.$fixture['shared']->id])->toMatchArray(['parent' => 'plan-'.$fixture['first']->id, 'series' => 1, 'editable' => false])
        ->and($rows['plan-milestone-'.$fixture['own']->id])->toMatchArray(['series' => 1, 'editable' => false])
        ->and($rows['plan-task-'.$fixture['task']->id])->toMatchArray(['parent' => 'plan-milestone-'.$fixture['shared']->id, 'editable' => false])
        ->and($rows)->not->toHaveKey('plan-milestone-'.$fixture['elsewhere']->id)
        ->and($rows)->not->toHaveKey('milestone-'.$fixture['own']->id)
        ->and($rows->firstWhere('name', 'School kits'))->toMatchArray(['series' => 2]);

    $component->assertSeeHtml('data-plan-series')->assertSeeHtml('data-series="2"');
});

test('the plan filter shows one proposal\'s plan and keeps its colour', function () {
    $fixture = ganttPlanFixture();

    $rows = collect(projectPage($fixture['project'])
        ->set('tab', 'plan')
        ->set('planProposal', (string) $fixture['second']->id)
        ->instance()->ganttPayload['rows'])->keyBy('id');

    expect($rows)->toHaveKey('plan-'.$fixture['second']->id)
        ->and($rows)->not->toHaveKey('plan-'.$fixture['first']->id)
        ->and($rows['plan-'.$fixture['second']->id]['series'])->toBe(2);
});

test('plan milestones and tasks cannot be moved from the project gantt', function () {
    $fixture = ganttPlanFixture();

    projectPage($fixture['project'])
        ->call('ganttReschedule', 'milestone-'.$fixture['own']->id, '2026-06-01', '2026-06-10')
        ->call('ganttReschedule', 'plan-milestone-'.$fixture['shared']->id, '2026-06-01', '2026-06-10')
        ->call('ganttReschedule', 'task-'.$fixture['task']->id, '2026-06-01', '2026-06-10')
        ->call('ganttRename', 'milestone-'.$fixture['own']->id, 'Hijacked');

    expect($fixture['own']->fresh())
        ->name->toBe('Our center launch')
        ->planned_start_date->toDateString()->toBe('2026-04-01')
        ->and($fixture['shared']->fresh()->planned_start_date->toDateString())->toBe('2026-04-01')
        ->and($fixture['task']->fresh()->start_date->toDateString())->toBe('2026-04-02');
});

test('a proposal activity with a calendar spans its scheduled months with its weeks named', function () {
    $fixture = ganttPlanFixture();
    $output = Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => $fixture['first']->id]);
    $activity = Result::factory()->activity($output)->create(['statement' => 'Run the winter clinics']);
    ActivitySchedule::query()->create(['result_id' => $activity->id, 'project_id' => null, 'month' => '2026-05-01', 'weeks' => [1, 3]]);
    ActivitySchedule::query()->create(['result_id' => $activity->id, 'project_id' => null, 'month' => '2026-06-01', 'weeks' => null]);

    $rows = ganttRowsFor($fixture['project']);

    expect($rows['plan-activity-'.$activity->id])->toMatchArray([
        'kind' => 'activity',
        'start' => '2026-05-01',
        'end' => '2026-06-30',
        'owner' => '2026-05 (1, 3), 2026-06',
        'editable' => false,
    ]);
});
