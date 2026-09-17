<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** Attach the user's account to an HR employee record, bypassing mass-assignment guarding. */
function linkUserToEmployee(User $user, int $employeeId): User
{
    $user->employee_id = $employeeId;
    $user->saveQuietly();

    return $user->refresh();
}

test('guests are redirected from the calendar', function () {
    $this->get(route('calendar.index'))->assertRedirect(route('login'));
});

test('a user with no linked HR employee sees an explanation instead of a calendar', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->assertSeeText('No linked employee record')
        ->assertDontSee('data-test="calendar-day-', false);
});

test('a linked user sees their own responsible milestones on the correct day, and not another employee\'s', function () {
    $employeeId = insertHrEmployee();
    $otherEmployeeId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Nassar');
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $mine = Milestone::factory()->create([
        'name' => 'My milestone',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);
    Milestone::factory()->create([
        'name' => 'Not mine',
        'responsible_user_id' => $otherEmployeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('data-test="calendar-milestone-'.$mine->id.'"', false)
        ->assertSee('My milestone')
        ->assertDontSee('Not mine');
});

test('clicking a day pre-fills the add milestone form with that date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateModal', '2026-06-15')
        ->assertSet('plannedDate', '2026-06-15')
        ->assertSet('showMilestoneModal', true)
        ->assertSet('responsibleUserId', (string) $employeeId);
});

test('saving from the calendar creates a milestone on the clicked date', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->call('openCreateModal', '2026-06-15')
        ->set('name', 'Kickoff review')
        ->set('milestoneProjectId', (string) $project->id)
        ->call('saveMilestone')
        ->assertHasNoErrors()
        ->assertSet('showMilestoneModal', false);

    $milestone = Milestone::query()->where('name', 'Kickoff review')->sole();

    expect($milestone->planned_date->toDateString())->toBe('2026-06-15')
        ->and($milestone->responsible_user_id)->toBe($employeeId);
});

test('month navigation shows the new month and hides the old one', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);

    $june = Milestone::factory()->create([
        'name' => 'June gate',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 10),
    ]);
    $july = Milestone::factory()->create([
        'name' => 'July gate',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 7, 10),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee('June gate')
        ->assertDontSee('July gate')
        ->call('nextMonth')
        ->assertSet('month', '2026-07')
        ->assertSee('July gate')
        ->assertDontSee('June gate');
});

test('a milestone can be edited and deleted from the calendar', function () {
    $employeeId = insertHrEmployee();
    $user = linkUserToEmployee(User::factory()->create(), $employeeId);
    $milestone = Milestone::factory()->create([
        'name' => 'Original name',
        'responsible_user_id' => $employeeId,
        'planned_date' => Carbon::create(2026, 6, 15),
    ]);

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('openEditModal', $milestone->id)
        ->assertSet('name', 'Original name')
        ->set('name', 'Renamed')
        ->call('saveMilestone')
        ->assertHasNoErrors();

    expect($milestone->refresh()->name)->toBe('Renamed');

    Livewire::actingAs($user)
        ->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('confirmDelete', $milestone->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteMilestone');

    $this->assertDatabaseMissing('milestones', ['id' => $milestone->id]);
});
