<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

/*
 * D-66 (W-02): a task of a paused project (its HR center is inactive) carries
 * the "Paused" chip on the task page, the desk Tasks panel and the
 * Department Tasks page. Due date and status stay untouched.
 */

beforeEach(function () {
    $this->travelTo('2026-10-15 10:30:00');

    $city = insertHrCity();
    $this->inactiveCenter = insertHrCenter($city, 'Closed', 'مغلق', isActive: false);
    $this->activeCenter = insertHrCenter($city, 'Open');

    $this->pausedProject = Project::factory()->create(['name' => 'Paused works', 'center_id' => $this->inactiveCenter, 'city_id' => $city]);
    $this->activeProject = Project::factory()->create(['name' => 'Running works', 'center_id' => $this->activeCenter, 'city_id' => $city]);
});

test('the task page shows the paused chip for a task of a paused project and keeps its due date', function () {
    $user = User::factory()->role('General Manager')->create();
    $paused = Task::factory()->create(['source_type' => 'project', 'source_id' => $this->pausedProject->id, 'title' => 'Paused task', 'due_date' => '2026-11-20']);
    $active = Task::factory()->create(['source_type' => 'project', 'source_id' => $this->activeProject->id, 'title' => 'Active task', 'due_date' => '2026-11-20']);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $paused])
        ->assertSeeHtml('data-test="paused-chip"')
        ->assertSeeText(__('tasks.paused_center_inactive'))
        ->assertSeeText('20');

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $active])
        ->assertDontSeeHtml('data-test="paused-chip"');
});

test('the task page shows the paused chip through a milestone of a paused project', function () {
    $user = User::factory()->role('General Manager')->create();
    $milestone = Milestone::factory()->create(['project_id' => $this->pausedProject->id]);
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Milestone task']);

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->assertSeeHtml('data-test="paused-chip"');
});

test('the desk tasks panel marks only the paused project\'s task', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $milestone = Milestone::factory()->create(['project_id' => $this->pausedProject->id]);

    foreach ([
        ['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Paused one'],
        ['source_type' => 'project', 'source_id' => $this->activeProject->id, 'title' => 'Active one'],
    ] as $attributes) {
        $task = Task::factory()->create([...$attributes, 'due_date' => '2026-11-20']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $me->employee_id]);
    }

    $html = Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->assertSeeText('Paused one')
        ->assertSeeText('Active one')
        ->html();

    expect(substr_count($html, 'data-test="paused-chip"'))->toBe(1);
});

test('the department tasks page marks a department task whose milestone belongs to a paused project', function () {
    $media = insertHrDepartment('Media');
    $employee = insertHrEmployee();
    insertHrContract($employee, insertHrPosition($media));
    $viewer = userWithRole('Media Team', $employee);
    $milestone = Milestone::factory()->create(['project_id' => $this->pausedProject->id]);

    Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'department_id' => $media,
        'requested_on' => '2026-10-15',
        'due_date' => '2026-11-20',
        'title' => 'Film the paused site',
    ]);

    Livewire::actingAs($viewer)
        ->test('pages::department-tasks.index')
        ->assertSeeText('Film the paused site')
        ->assertSeeHtml('data-test="paused-chip"')
        ->assertSeeText(__('tasks.paused_center_inactive'))
        ->assertSeeHtml('datetime="2026-11-20"');
});
