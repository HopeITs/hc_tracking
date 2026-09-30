<?php

use App\Enums\DependencyType;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return Livewire\Features\SupportTesting\Testable
 */
function schedulePage(Project $project)
{
    return Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::projects.show', ['project' => $project]);
}

/**
 * Two milestones in one project: A (Mon 5 – Fri 9 Oct 2026) and B (Mon 5 – Tue 6 Oct).
 *
 * @return array{0: Project, 1: Milestone, 2: Milestone}
 */
function twoMilestones(): array
{
    $project = Project::factory()->create();
    $a = Milestone::factory()->create(['project_id' => $project->id, 'sequence' => 1, 'planned_start_date' => '2026-10-05', 'planned_end_date' => '2026-10-09']);
    $b = Milestone::factory()->create(['project_id' => $project->id, 'sequence' => 2, 'planned_start_date' => '2026-10-05', 'planned_end_date' => '2026-10-06']);

    return [$project, $a, $b];
}

test('a finish-to-start link pushes the successor past the weekend keeping its working-day duration', function () {
    [$project, $a, $b] = twoMilestones();

    $result = schedulePage($project)->instance()->ganttLink('milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 0);

    expect($result['ok'])->toBeTrue()
        ->and($b->fresh()->planned_start_date->toDateString())->toBe('2026-10-12')
        ->and($b->fresh()->planned_end_date->toDateString())->toBe('2026-10-13')
        ->and($result['changes'])->toBe([['id' => 'milestone-'.$b->id, 'start' => '2026-10-05', 'end' => '2026-10-06']])
        ->and($result['previous'])->toBeNull()
        ->and(ScheduleDependency::query()->sole())
        ->type->toBe(DependencyType::FinishToStart)
        ->project_id->toBe($project->id);
});

test('lag is counted in working days on the project calendar', function () {
    [$project, $a, $b] = twoMilestones();
    $project->update(['schedule_weekend' => 'fri-sat']);

    schedulePage($project)->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 2);

    // A ends Fri 9 (a day off here), so the start moves 1 + 2 working days on: Sun 11, Mon 12, Tue 13.
    expect($b->fresh()->planned_start_date->toDateString())->toBe('2026-10-13');
});

test('start-to-start and finish-to-finish links pin the matching edges', function () {
    [$project, $a, $b] = twoMilestones();
    $page = schedulePage($project);

    $page->call('ganttReschedule', 'milestone-'.$b->id, '2026-10-01', '2026-10-01');
    $page->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'ss', 1);
    expect($b->fresh()->planned_start_date->toDateString())->toBe('2026-10-06');

    $page->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'ff', 0);
    expect($b->fresh()->planned_end_date->toDateString())->toBe('2026-10-09');
});

test('links never pull a successor earlier', function () {
    [$project, $a, $b] = twoMilestones();
    $b->update(['planned_start_date' => '2026-11-02', 'planned_end_date' => '2026-11-03']);

    schedulePage($project)->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 0);

    expect($b->fresh()->planned_start_date->toDateString())->toBe('2026-11-02');
});

test('moving a predecessor ripples down the chain and a restore puts every moved row back', function () {
    [$project, $a, $b] = twoMilestones();
    $page = schedulePage($project);
    $page->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 0);

    $result = $page->instance()->ganttReschedule('milestone-'.$a->id, '2026-10-12', '2026-10-16');

    expect($b->fresh()->planned_start_date->toDateString())->toBe('2026-10-19')
        ->and(collect($result['changes'])->pluck('id')->all())->toBe(['milestone-'.$a->id, 'milestone-'.$b->id]);

    $page->instance()->ganttRestore($result['changes']);

    expect($a->fresh()->planned_start_date->toDateString())->toBe('2026-10-05')
        ->and($b->fresh()->planned_start_date->toDateString())->toBe('2026-10-12');
});

test('a link that closes a loop is refused', function () {
    [$project, $a, $b] = twoMilestones();
    $page = schedulePage($project);
    $page->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 0);

    $page->call('ganttLink', 'milestone-'.$b->id, 'milestone-'.$a->id, 'fs', 0);

    expect(ScheduleDependency::query()->count())->toBe(1)
        ->and($a->fresh()->planned_start_date->toDateString())->toBe('2026-10-05');
});

test('links to rows of another project or of an unknown type are refused', function () {
    [$project, $a, $b] = twoMilestones();
    $foreign = Milestone::factory()->create();

    schedulePage($project)
        ->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$foreign->id, 'fs', 0)
        ->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'zz', 0)
        ->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$a->id, 'fs', 0)
        ->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 999);

    expect(ScheduleDependency::query()->count())->toBe(0);
});

test('a summary cannot be linked to its own sub-items', function () {
    [$project, $a] = twoMilestones();
    $child = Milestone::factory()->childOf($a)->create(['planned_start_date' => '2026-10-06', 'planned_end_date' => '2026-10-07']);
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $a->id, 'start_date' => '2026-10-05', 'due_date' => '2026-10-06']);

    schedulePage($project)
        ->call('ganttLink', 'milestone-'.$child->id, 'milestone-'.$a->id, 'fs', 0)
        ->call('ganttLink', 'milestone-'.$a->id, 'task-'.$task->id, 'fs', 0);

    expect(ScheduleDependency::query()->count())->toBe(0);
});

test('editing a link reports what it replaced and unlinking removes it', function () {
    [$project, $a, $b] = twoMilestones();
    $page = schedulePage($project)->instance();
    $page->ganttLink('milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 0);

    $edited = $page->ganttLink('milestone-'.$a->id, 'milestone-'.$b->id, 'ss', 3);
    $removed = $page->ganttUnlink('milestone-'.$a->id, 'milestone-'.$b->id);

    expect($edited['previous'])->toBe(['type' => 'fs', 'lag' => 0])
        ->and($removed['previous'])->toBe(['type' => 'ss', 'lag' => 3])
        ->and(ScheduleDependency::query()->count())->toBe(0);
});

test('tasks link to milestones of the same project', function () {
    [$project, $a, $b] = twoMilestones();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $b->id, 'start_date' => '2026-10-01', 'due_date' => '2026-10-02']);

    schedulePage($project)->call('ganttLink', 'milestone-'.$a->id, 'task-'.$task->id, 'fs', 0);

    expect($task->fresh()->start_date->toDateString())->toBe('2026-10-12')
        ->and($task->fresh()->due_date->toDateString())->toBe('2026-10-13');
});

test('the payload marks the critical chain and prints MS Project predecessors', function () {
    [$project, $a, $b] = twoMilestones();
    $slack = Milestone::factory()->create(['project_id' => $project->id, 'sequence' => 3, 'planned_start_date' => '2026-10-05', 'planned_end_date' => '2026-10-05']);
    $page = schedulePage($project);
    $page->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 2);

    $payload = $page->instance()->ganttPayload;
    $rows = collect($payload['rows'])->keyBy('id');

    expect($rows['milestone-'.$a->id]['critical'])->toBeTrue()
        ->and($rows['milestone-'.$b->id]['critical'])->toBeTrue()
        ->and($rows['milestone-'.$slack->id]['critical'])->toBeFalse()
        ->and($rows['milestone-'.$slack->id]['float'])->toBeGreaterThan(0)
        ->and($rows['milestone-'.$b->id]['predecessors'])->toBe($rows['milestone-'.$a->id]['wbs'].'FS+2d')
        ->and($payload['links'])->toHaveCount(1)
        ->and($payload['links'][0])->toMatchArray(['from' => 'milestone-'.$a->id, 'to' => 'milestone-'.$b->id, 'type' => 'fs', 'lag' => 2]);
});

test('the working week is saved on the project and reported back for undo', function () {
    [$project] = twoMilestones();

    $result = schedulePage($project)->instance()->ganttSetWeekend('fri-sat');

    expect($project->fresh()->schedule_weekend)->toBe('fri-sat')
        ->and($result['previous'])->toBe('sat-sun')
        ->and($result['payload']['weekend'])->toBe('fri-sat');

    schedulePage($project)->call('ganttSetWeekend', 'mon');

    expect($project->fresh()->schedule_weekend)->toBe('fri-sat');
});

test('a new project defaults to a saturday-sunday weekend', function () {
    expect(Project::factory()->create()->fresh()->schedule_weekend)->toBe('sat-sun');
});

test('a baseline freezes the schedule and later slippage shows against it', function () {
    [$project, $a] = twoMilestones();
    $page = schedulePage($project);

    $page->call('ganttSaveBaseline', 'Approved plan');
    $baseline = ScheduleBaseline::query()->sole();
    $page->call('ganttReschedule', 'milestone-'.$a->id, '2026-10-12', '2026-10-16');

    $row = collect($page->instance()->ganttPayload['rows'])->firstWhere('id', 'milestone-'.$a->id);

    expect($baseline->name)->toBe('Approved plan')
        ->and($baseline->items()->count())->toBe(2)
        ->and($page->get('ganttBaselineId'))->toBe($baseline->id)
        ->and($row)->toMatchArray(['baselineStart' => '2026-10-05', 'baselineEnd' => '2026-10-09', 'start' => '2026-10-12']);

    $page->call('ganttShowBaseline', null);
    expect(collect($page->instance()->ganttPayload['rows'])->firstWhere('id', 'milestone-'.$a->id)['baselineStart'])->toBeNull();

    $page->call('ganttDeleteBaseline', $baseline->id);
    expect(ScheduleBaseline::query()->count())->toBe(0);
});

test('a baseline of another project cannot be shown or deleted', function () {
    [$project] = twoMilestones();
    $foreign = ScheduleBaseline::factory()->create();

    schedulePage($project)
        ->call('ganttShowBaseline', $foreign->id)
        ->assertSet('ganttBaselineId', null)
        ->call('ganttDeleteBaseline', $foreign->id);

    expect($foreign->fresh())->not->toBeNull();
});

test('deleting a milestone or task removes its links and baseline rows', function () {
    [$project, $a, $b] = twoMilestones();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $a->id, 'start_date' => '2026-10-01', 'due_date' => '2026-10-02']);
    $page = schedulePage($project);
    $page->call('ganttLink', 'milestone-'.$a->id, 'milestone-'.$b->id, 'fs', 0);
    $page->call('ganttLink', 'task-'.$task->id, 'milestone-'.$b->id, 'fs', 0);
    $page->call('ganttSaveBaseline', '');

    $task->delete();
    expect(ScheduleDependency::query()->count())->toBe(1);

    $b->delete();
    expect(ScheduleDependency::query()->count())->toBe(0)
        ->and(ScheduleBaseline::query()->sole()->items()->count())->toBe(1);
});
