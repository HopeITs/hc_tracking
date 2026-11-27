<?php

use App\Enums\AssignmentStatus;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\HrContract;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\QuickActions;
use Livewire\Livewire;

/**
 * A draft **place** in a program run by a manager, put in the charge of
 * $ownerEmployee by an assigner.
 *
 * Y-17: the appointment belongs to the location row — `ProposalLocation`
 * raises it from `responsible_employee_id`, and nothing is asked of a
 * project any more (`Project::assignmentFields()` is empty). `project` is
 * the row, so every case below reads the place.
 *
 * @return array{project: ProposalLocation, owner: User, assigner: User, programManager: User}
 */
function projectAssignedToANewOwner(): array
{
    $managerEmployee = insertHrEmployee(firstNameEn: 'Mona', lastNameEn: 'Manager');
    $programManager = userWithRole('MEAL', $managerEmployee);
    $ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $owner = userWithRole('Managers', $ownerEmployee);
    $assigner = userWithRole('General Manager', insertHrEmployee(firstNameEn: 'Gina', lastNameEn: 'General'));

    $row = Activity::withoutRecording(function () use ($managerEmployee): ProposalLocation {
        $project = Project::factory()->create([
            'name' => 'Winter Support',
            'program_id' => Program::factory()->create(['manager_id' => $managerEmployee])->id,
            'user_id' => insertHrEmployee(),
        ]);

        return rowFor($project);
    });

    test()->actingAs($assigner);
    $row->update(['responsible_employee_id' => $ownerEmployee]);

    return ['project' => $row->fresh(), 'owner' => $owner, 'assigner' => $assigner, 'programManager' => $programManager];
}

test('putting someone in charge asks them to acknowledge or reject, as important', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner] = projectAssignedToANewOwner();

    $assignment = $project->currentAssignment('responsible_employee_id');
    $notification = UserNotification::query()->forUser($owner)->sole();

    expect($assignment->status)->toBe(AssignmentStatus::Pending)
        ->and($assignment->employee_id)->toBe($owner->employee_id)
        ->and($assignment->assigned_by)->toBe($assigner->id)
        ->and($assignment->seen_at)->toBeNull()
        ->and($notification->priority)->toBe(NotificationPriority::Important)
        ->and(array_column($notification->data['actions'], 'type'))->toBe(['assignment.acknowledge', 'assignment.reject']);
});

test('putting yourself in charge needs nobody to agree', function () {
    $employee = insertHrEmployee();
    $self = userWithRole('General Manager', $employee);
    $this->actingAs($self);

    $project = ProposalLocation::factory()->complete()->create(['responsible_employee_id' => $employee]);

    expect($project->currentAssignment('responsible_employee_id')->status)->toBe(AssignmentStatus::Acknowledged)
        ->and($project->isAssignmentAcknowledged('responsible_employee_id'))->toBeTrue();
});

test('reassigning keeps every row and only the latest one stands', function () {
    ['project' => $project] = projectAssignedToANewOwner();
    $nextEmployee = insertHrEmployee();

    $project->update(['responsible_employee_id' => $nextEmployee]);

    $rows = $project->assignments()->orderBy('id')->get();

    expect($rows->pluck('status')->all())->toBe([AssignmentStatus::Superseded, AssignmentStatus::Pending])
        ->and($project->currentAssignment('responsible_employee_id')->employee_id)->toBe($nextEmployee);
});

test('bulk loads inside withoutRecording ask nobody', function () {
    $project = Activity::withoutRecording(fn () => Project::factory()->create());

    expect(Assignment::query()->count())->toBe(0);
});

test('acknowledging with a note tells the assigner and the program manager', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner, 'programManager' => $programManager] = projectAssignedToANewOwner();

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->call('openAssignmentModal', 'acknowledge')
        ->set('assignmentNote', 'Accepted, but I am on leave until the 15th')
        ->call('submitAssignment')
        ->assertHasNoErrors();

    $assignment = $project->currentAssignment('responsible_employee_id');

    expect($assignment->status)->toBe(AssignmentStatus::Acknowledged)
        ->and($assignment->response_note)->toBe('Accepted, but I am on leave until the 15th')
        ->and($assignment->responded_by)->toBe($owner->id)
        ->and($project->fresh()->isAssignmentAcknowledged('responsible_employee_id'))->toBeTrue()
        ->and(sentencesFor($assigner))->toContain($owner->name.' acknowledged being the owner of “'.$project->label().'”')
        ->and(sentencesFor($programManager))->toContain($owner->name.' acknowledged being the owner of “'.$project->label().'”');
});

test('opening the project marks the assignment as seen, once', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();

    $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();
    $seenAt = $project->currentAssignment('responsible_employee_id')->seen_at;

    $this->travel(5)->minutes();
    $this->get(route('projects.show', $project))->assertOk();

    expect($seenAt)->not->toBeNull()
        ->and($project->currentAssignment('responsible_employee_id')->seen_at->equalTo($seenAt))->toBeTrue()
        ->and($project->currentAssignment('responsible_employee_id')->status)->toBe(AssignmentStatus::Pending);
});

test('rejecting needs a reason', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->call('openAssignmentModal', 'reject')
        ->set('assignmentNote', 'no')
        ->call('submitAssignment')
        ->assertHasErrors('assignmentNote');

    expect($project->currentAssignment('responsible_employee_id')->status)->toBe(AssignmentStatus::Pending);
});

test('a rejection reaches the assigner and the program manager with the reason', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner, 'programManager' => $programManager] = projectAssignedToANewOwner();

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->call('openAssignmentModal', 'reject')
        ->set('assignmentNote', 'Already running three projects')
        ->call('submitAssignment')
        ->assertHasNoErrors();

    $sentence = $owner->name.' rejected being the owner of “'.$project->label().'”: Already running three projects';

    expect($project->currentAssignment('responsible_employee_id')->status)->toBe(AssignmentStatus::Rejected)
        ->and(sentencesFor($assigner))->toContain($sentence)
        ->and(sentencesFor($programManager))->toContain($sentence)
        ->and(UserNotification::query()->forUser($programManager)->get()->contains(fn (UserNotification $row): bool => $row->priority === NotificationPriority::Important))->toBeTrue()
        ->and(sentencesFor($owner))->not->toContain($sentence);
});

test('only the assignee can respond, and only while it is pending', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner] = projectAssignedToANewOwner();
    $assignment = $project->currentAssignment('responsible_employee_id');

    expect($assigner->can('respond', $assignment))->toBeFalse()
        ->and($owner->can('respond', $assignment))->toBeTrue();

    Livewire::actingAs($assigner)->test('pages::projects.show', ['project' => $project->slug])->call('openAssignmentModal', 'reject')->assertForbidden();

    $assignment->acknowledge($owner);

    expect($owner->can('respond', $assignment->fresh()))->toBeFalse();
});

test('the quick actions acknowledge in one click and send rejection to the reason form', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();
    $notification = UserNotification::query()->forUser($owner)->sole();

    $reject = app(QuickActions::class)->run($owner, $notification, 'assignment.reject');
    $acknowledge = app(QuickActions::class)->run($owner, $notification, 'assignment.acknowledge');
    $again = app(QuickActions::class)->run($owner, $notification, 'assignment.acknowledge');

    expect($reject['redirect'])->toBe(route('projects.show', $project).'?respond=reject')
        ->and($acknowledge['ok'])->toBeTrue()
        ->and($project->currentAssignment('responsible_employee_id')->status)->toBe(AssignmentStatus::Acknowledged)
        ->and($again['ok'])->toBeFalse()
        ->and($again['message'])->toBe('notifications.quick.already_done');
});

test('a rejection locks the project for everyone: no edit, no new owner', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner, 'programManager' => $programManager] = projectAssignedToANewOwner();
    $superAdmin = userWithRole('Super Admin');

    expect($assigner->can('update', $project))->toBeTrue();

    $project->currentAssignment('responsible_employee_id')->reject($owner, 'Already running three projects');
    $locked = $project->fresh();

    expect($assigner->can('update', $locked))->toBeFalse()
        ->and($assigner->can('reassign', $locked))->toBeFalse()
        ->and($superAdmin->can('update', $locked))->toBeFalse()
        ->and($superAdmin->can('reassign', $locked))->toBeFalse()
        ->and($programManager->can('reassign', $locked))->toBeFalse()
        ->and($owner->can('update', $locked))->toBeFalse()
        ->and($assigner->can('delete', $locked))->toBeTrue();

    Livewire::actingAs($assigner)->test('pages::projects.index')->call('openEditModal', $project->id)->assertForbidden();
});

test('the rejected project tells the assigner that nothing can be done any more', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner] = projectAssignedToANewOwner();
    $project->currentAssignment('responsible_employee_id')->reject($owner, 'Already running three projects');

    Livewire::actingAs($assigner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->assertSeeHtml('data-status="rejected"')
        ->assertSee('Already running three projects')
        ->assertSee(__('projects.assignment_rejected_hint'))
        ->assertDontSeeHtml('data-test="assignment-reconfirm-button"');
});

test('the projects page badges a rejected owner and nothing else', function () {
    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner] = projectAssignedToANewOwner();

    Livewire::actingAs($assigner)->test('pages::projects.index')->assertDontSeeHtml('data-test="owner-rejected-badge"');

    $project->currentAssignment('responsible_employee_id')->reject($owner, 'Already running three projects');

    Livewire::actingAs($assigner)->test('pages::projects.index')->assertSeeHtml('data-test="owner-rejected-badge"')->assertSee('Rejected');
});

test('a rejected project shows Blocked instead of edit, and only the CEO can unblock it with a note', function () {
    $department = insertHrDepartment();
    $topEmployee = insertHrEmployee(firstNameEn: 'Fredy', lastNameEn: 'Top');
    $top = userWithRole('General Manager', $topEmployee);
    insertHrContract($topEmployee, insertHrPosition($department, 'Chief Executive Officer'));

    ['project' => $project, 'owner' => $owner, 'assigner' => $assigner] = projectAssignedToANewOwner();
    $project->currentAssignment('responsible_employee_id')->reject($owner, 'Already running three projects');

    Livewire::actingAs($assigner)->test('pages::projects.index')
        ->assertSeeHtml('data-test="blocked-badge"')
        ->assertDontSeeHtml('data-test="blocked-badge-unblock"')
        ->assertDontSeeHtml('wire:click="openEditModal('.$project->id.')"')
        ->call('openUnblockModal', $project->id)->assertForbidden();

    $rejected = $project->fresh()->ownerAssignment;
    expect($assigner->can('unblock', $rejected))->toBeFalse();

    Livewire::actingAs($top)->test('pages::projects.index')
        ->assertSeeHtml('data-test="blocked-badge-unblock"')
        ->assertDontSeeHtml('wire:click="openEditModal('.$project->id.')"')
        ->call('openUnblockModal', $project->id)
        ->set('unblockNote', 'Talked to Omar, he will take it')
        ->call('unblockProject')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="blocked-badge"')
        ->assertSeeHtml('wire:click="openEditModal('.$project->id.')"');

    $current = $project->fresh()->currentAssignment('responsible_employee_id');

    expect($rejected->fresh()->status)->toBe(AssignmentStatus::Superseded)
        ->and($current->status)->toBe(AssignmentStatus::Pending)
        ->and($current->employee_id)->toBe($owner->employee_id)
        ->and($assigner->can('update', $project->fresh()))->toBeTrue()
        ->and(Activity::query()->where('event', 'project.assignment_unblocked')->sole()->properties['note'])->toBe('Talked to Omar, he will take it');
});

test('the top level is the holder of the configured position title, and hears only a program manager rejecting', function () {
    $department = insertHrDepartment();
    $topEmployee = insertHrEmployee(firstNameEn: 'Fredy', lastNameEn: 'Top');
    $top = userWithRole('MEAL', $topEmployee);
    $chief = insertHrPosition($department, 'Chief Executive Officer');
    insertHrContract($topEmployee, $chief);
    insertHrPosition($department, 'Head of Finance', directManagerId: $chief);
    insertHrPosition($department, 'Head of IT', directManagerId: $chief);

    // The deputy has no direct manager either, but not the configured title.
    $deputyEmployee = insertHrEmployee(firstNameEn: 'Dina', lastNameEn: 'Deputy');
    $deputy = userWithRole('MEAL', $deputyEmployee);
    $deputyPosition = insertHrPosition($department, 'Deputy Chief Executive Officer');
    insertHrContract($deputyEmployee, $deputyPosition);
    insertHrPosition($department, 'Assistant', directManagerId: $deputyPosition);

    expect(HrContract::topLevelEmployeeIds())->toBe([$topEmployee]);

    // An ordinary owner rejects: the top level is not told.
    ['project' => $first, 'owner' => $owner] = projectAssignedToANewOwner();
    $this->actingAs($owner);
    $first->currentAssignment('responsible_employee_id')->reject($owner, 'Already running three projects');

    expect(sentencesFor($top))->toBe([]);

    // A program manager (answers to the top level) acknowledges: still not told.
    $managerEmployee = insertHrEmployee(firstNameEn: 'Pam', lastNameEn: 'Programs');
    insertHrContract($managerEmployee, insertHrPosition($department, 'Program Manager', directManagerId: $chief));
    $programManager = userWithRole('MEAL', $managerEmployee);
    Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $managerEmployee]));
    $assigner = userWithRole('General Manager');
    $second = Activity::withoutRecording(fn () => rowFor(Project::factory()->create(['name' => 'Spring Support'])));
    $this->actingAs($assigner);
    $second->update(['responsible_employee_id' => $managerEmployee]);
    $this->actingAs($programManager);
    $second->currentAssignment('responsible_employee_id')->acknowledge($programManager);

    expect(sentencesFor($top))->toBe([]);

    // A program manager rejects: now the top level is told.
    $third = Activity::withoutRecording(fn () => rowFor(Project::factory()->create(['name' => 'Summer Support'])));
    $this->actingAs($assigner);
    $third->update(['responsible_employee_id' => $managerEmployee]);
    $this->actingAs($programManager);
    $third->currentAssignment('responsible_employee_id')->reject($programManager, 'Already running three projects');

    // A row is named by its place (Y-16), not by the project's name.
    expect(sentencesFor($top)[0])->toStartWith($programManager->name.' rejected being the owner of “'.$third->fresh()->label().'”');
    expect(sentencesFor($deputy))->toBe([]);
});

/*
| Y-17: three cases went with the create form, which the [contract] removes
| in §8 — a new project's starting status whatever the form sends, the owner
| dropdown listing only program managers, and a programme picking its
| manager. A place is placed and owned on the proposal now. They are named
| in the Y-17 handoff.
*/

test('editing a project through the form leaves its status alone', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();
    $project->currentAssignment('responsible_employee_id')->acknowledge($owner);
    $project->update(['status' => ProjectStatus::Active, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->set('description', 'Revised through the form')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh())
        ->description->toBe('Revised through the form')
        ->status->toBe(ProjectStatus::Active);
});

test('answering on the page reads the notification and takes its buttons away', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();
    $before = UserNotification::query()->forUser($owner)->sole();

    expect($before->read_at)->toBeNull()
        ->and(array_column($before->data['actions'], 'type'))->toBe(['assignment.acknowledge', 'assignment.reject']);

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->call('openAssignmentModal', 'reject')
        ->set('assignmentNote', 'Already running three projects')
        ->call('submitAssignment');

    $after = UserNotification::query()->forUser($owner)->sole();

    expect($after->read_at)->not->toBeNull()
        ->and($after->data['actions'])->toBe([]);
});

test('opening the project reads the assignment notification but keeps its buttons', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();

    $this->actingAs($owner)->get(route('projects.show', $project))->assertOk();

    $row = UserNotification::query()->forUser($owner)->sole();

    expect($row->read_at)->not->toBeNull()
        ->and(array_column($row->data['actions'], 'type'))->toBe(['assignment.acknowledge', 'assignment.reject']);
});

test('answering from the bell updates an open project page', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();
    $notification = UserNotification::query()->forUser($owner)->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->assertSeeHtml('data-status="pending"');

    app(QuickActions::class)->run($owner, $notification, 'assignment.acknowledge');

    $page->dispatch('notification-received')->assertSeeHtml('data-status="acknowledged"')->assertDontSeeHtml('data-status="pending"');
});

test('reassigning takes the old owner\'s buttons away', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();

    $project->update(['responsible_employee_id' => insertHrEmployee()]);

    expect(UserNotification::query()->forUser($owner)->sole()->data['actions'])->toBe([]);
});

test('acknowledging on the project page approves a draft waiting on a donor-approved proposal, and rejecting keeps it a draft', function () {
    ['project' => $project, 'owner' => $owner] = projectAssignedToANewOwner();
    // D-93: a place is of exactly one proposal, so the funding one is its own.
    $project->proposal->forceFill([
        'status' => ProposalStatus::Approved,
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
    ])->saveQuietly();
    $project->forceFill(['start_date' => '2026-11-01', 'end_date' => '2027-10-31'])->saveQuietly();

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->call('openAssignmentModal', 'reject')
        ->set('assignmentNote', 'I cannot take this project on.')
        ->call('submitAssignment');

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($project->fresh()->isOwnerRejected())->toBeTrue();

    // The top level lifting the block is covered by ProposalApprovalTest; here the same person is asked again.
    $project->currentAssignment('responsible_employee_id')->forceFill(['status' => AssignmentStatus::Pending])->save();

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project->slug])
        ->call('openAssignmentModal', 'acknowledge')
        ->call('submitAssignment');

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});
