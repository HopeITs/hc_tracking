<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

test('a project with a late monthly report is off track, one with a milestone due this week needs a look', function () {
    $late = Project::factory()->create(['name' => 'Late Wells']);
    MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-07-01']);
    $soon = Project::factory()->create(['name' => 'Soon Clinic']);
    Milestone::factory()->create(['project_id' => $soon->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-09-18']);
    $calm = Project::factory()->create(['name' => 'Calm School']);

    $panel = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.projects', ['size' => 'XL'])->call('setScope', 'all');
    $lines = $panel->instance()->lines;

    expect($lines[$late->id]['health'])->toBe('bad')
        ->and($lines[$soon->id]['health'])->toBe('warn')
        ->and($lines[$calm->id]['health'])->toBe('good');

    $panel->assertSeeInOrder(['Late Wells', 'Soon Clinic', 'Calm School']);
});

test('a project takes its stage from its leading proposal', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalReview]);
    $project->proposals()->attach($proposal);

    $lines = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.projects', ['size' => 'XL'])->instance()->lines;

    expect($lines[$project->id]['stage'])->toBe(2);
});

test('ticking a task completes it, and only when the viewer may change its status', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $mine = Activity::withoutRecording(function () use ($me): Task {
        $task = Task::factory()->create(['title' => 'Mine to finish', 'due_date' => '2026-09-20']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $me->employee_id]);

        return $task;
    });

    Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->assertSeeHtml('wire:click="complete('.$mine->id.')"')
        ->call('complete', $mine->id);

    expect($mine->fresh()->status)->toBe(TaskStatus::Done);
});

test('a task outside the viewer\'s list cannot be completed from the panel', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $other = Task::factory()->create(['title' => 'Somebody else']);

    Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])->call('complete', $other->id);

    expect($other->fresh()->status)->not->toBe(TaskStatus::Done);
});

test('waiting on others lists my monthly reports at MEAL and my late tasks held by others', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $colleague = insertHrEmployee(firstNameEn: 'Rana');
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'user_id' => $me->employee_id]);
    MonthlyReport::factory()->submitted()->create(['project_id' => $project->id, 'period' => '2026-08-01', 'submitted_by' => $me->id]);
    Activity::withoutRecording(function () use ($me, $colleague): void {
        $task = Task::factory()->create(['title' => 'Book the venue', 'due_date' => '2026-09-01', 'created_by' => $me->id]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $colleague]);
    });

    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])
        ->assertSee('Book the venue')
        ->assertSee('Aleppo Wells');
});

test('donor reporting shows every open report to agreement viewers and only one\'s own to others', function () {
    $employeeId = insertHrEmployee();
    $responsible = userWithRole('Team Member', $employeeId);
    DonorReport::factory()->create(['title' => 'Mine to write', 'responsible_employee_id' => $employeeId, 'due_on' => '2026-09-30']);
    DonorReport::factory()->create(['title' => 'Someone else\'s', 'due_on' => '2026-09-30']);

    Livewire::actingAs($responsible)->test('desk.panels.duties', ['size' => 'M'])
        ->assertSee('Mine to write')
        ->assertDontSee('Someone else');

    Livewire::actingAs(userWithRole('Financial'))->test('desk.panels.duties', ['size' => 'M'])
        ->assertSee('Mine to write')
        ->assertSee('Someone else');
});

test('department deliverables show the Media Team media work only and hide finance work from those without money', function () {
    Task::factory()->mediaDeliverable()->forProposal(Proposal::factory()->create())->create(['title' => 'Shoot the opening']);
    Task::factory()->financial()->forProposal(Proposal::factory()->create())->create(['title' => 'Pay the contractor']);

    Livewire::actingAs(userWithRole('Media Team'))->test('desk.panels.deliverables', ['size' => 'M'])
        ->assertDontSee('Pay the contractor');

    Livewire::actingAs(userWithRole('Financial'))->test('desk.panels.deliverables', ['size' => 'M'])
        ->assertSee('Pay the contractor')
        ->assertDontSee('Shoot the opening');
});

test('risks list open ones before the ones that happened', function () {
    Risk::factory()->happened()->create(['name' => 'Happened risk']);
    Risk::factory()->create(['name' => 'Open risk']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.risks', ['size' => 'M'])
        ->assertSeeInOrder(['Open risk', 'Happened risk']);
});

test('someone who owns no project sees every project without picking a scope', function () {
    Project::factory()->create(['name' => 'Aleppo Wells']);

    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.projects', ['size' => 'XL'])
        ->assertSee('Aleppo Wells')
        ->assertDontSeeHtml('id="ps-projects-scope"');
});

test('an owner starts on their own projects and can switch to all', function () {
    $owner = userWithRole('Managers', insertHrEmployee());
    Project::factory()->create(['name' => 'My Wells', 'user_id' => $owner->employee_id]);
    Project::factory()->create(['name' => 'Their Clinic']);

    Livewire::actingAs($owner)->test('desk.panels.projects', ['size' => 'XL'])
        ->assertSee('My Wells')
        ->assertDontSee('Their Clinic')
        ->call('setScope', 'all')
        ->assertSee('Their Clinic');
});
