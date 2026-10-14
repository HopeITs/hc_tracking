<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\ProjectDatesScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

// 2026-10-14 is a Wednesday; 2026-10-17/18 is a weekend (Mon–Fri default).
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ownerEmployee = insertHrEmployee();
    $this->managerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->programManager = userWithRole('Managers', $this->managerEmployee);
    $this->meal = userWithRole('MEAL');
    $this->generalManager = userWithRole('General Manager');
    $this->bystander = userWithRole('Media Team');
    $this->program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $this->managerEmployee]));
});

/**
 * A project of the shared program, in the given status, with fixed dates.
 *
 * @param  array<string, mixed>  $attributes
 */
function datedProject(ProjectStatus $status, string $start, string $end, array $attributes = []): Project
{
    return Activity::withoutRecording(fn () => Project::factory()->status($status)->create([
        'program_id' => test()->program->id,
        'user_id' => test()->ownerEmployee,
        'start_date' => $start,
        'end_date' => $end,
        'name' => 'Water Network',
        ...$attributes,
    ]));
}

function scanProjectDates(string $at): int
{
    return app(ProjectDatesScanner::class)->scan(CarbonImmutable::parse($at));
}

/**
 * @return list<int> user ids holding a notification with this key on this project
 */
function projectDateRecipients(Project $project, string $key): array
{
    $ids = UserNotification::query()
        ->where('data->key', $key)
        ->where('subject_type', 'project')
        ->where('subject_id', $project->id)
        ->pluck('notifiable_id')
        ->map(fn (int|string $id): int => (int) $id)
        ->all();
    sort($ids);

    return $ids;
}

/**
 * @param  list<int>  $ids
 * @return list<int>
 */
function sortedUserIds(array $ids): array
{
    sort($ids);

    return $ids;
}

test('an active project announces its start to the owner, program manager, MEAL and General Management', function () {
    $project = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))
        ->toBe(sortedUserIds([$this->owner->id, $this->programManager->id, $this->meal->id, $this->generalManager->id]))
        ->and(sentencesFor($this->owner))->toBe(['The project “Water Network” started on Oct 14, 2026']);
});

test('an active project announces its end on the end date', function () {
    $project = datedProject(ProjectStatus::Active, '2025-10-01', '2026-10-14');

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($project, 'notifications.alerts.project_ended'))
        ->toBe(sortedUserIds([$this->owner->id, $this->programManager->id, $this->meal->id, $this->generalManager->id]))
        ->and(projectDateRecipients($project, 'notifications.alerts.project_started'))->toBe([]);
});

test('a second run sends nothing more', function () {
    datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');

    expect(scanProjectDates('2026-10-14 06:00'))->toBe(4)
        ->and(scanProjectDates('2026-10-14 09:00'))->toBe(0);
});

test('a weekend start is announced on the next working day', function () {
    $project = datedProject(ProjectStatus::Active, '2026-10-17', '2027-06-30');

    scanProjectDates('2026-10-17 06:00');
    scanProjectDates('2026-10-18 06:00');
    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toBe([]);

    scanProjectDates('2026-10-19 06:00');
    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toHaveCount(4);
});

test('the next working day is the project\'s own', function () {
    // Sunday–Thursday project: Friday the 16th is its weekend, Sunday the 18th its next working day.
    $project = datedProject(ProjectStatus::Active, '2026-10-16', '2027-06-30', ['working_days' => [7, 1, 2, 3, 4]]);

    scanProjectDates('2026-10-16 06:00');
    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toBe([]);

    scanProjectDates('2026-10-18 06:00');
    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toHaveCount(4);
});

test('a moved date re-arms the notice', function () {
    $project = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');
    scanProjectDates('2026-10-14 06:00');

    Activity::withoutRecording(fn () => $project->forceFill(['start_date' => '2026-10-15'])->save());
    scanProjectDates('2026-10-15 06:00');

    expect(UserNotification::query()->forUser($this->owner)->where('data->key', 'notifications.alerts.project_started')->count())->toBe(2);
});

test('a project activated after its start date gets no start notice', function (string $event, array $changes) {
    $project = datedProject(ProjectStatus::Active, '2026-10-17', '2027-06-30');
    Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null,
        'subject_type' => 'project',
        'subject_id' => $project->id,
        'module' => 'projects',
        'event' => $event,
        'changes' => $changes,
        'properties' => [],
    ])->forceFill(['created_at' => '2026-10-19 05:00'])->save());

    scanProjectDates('2026-10-19 06:00');

    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toBe([]);
})->with([
    'activation event' => ['project.activated', []],
    'status change to active' => ['project.status_changed', ['status' => ['approved', 'active']]],
]);

test('a project activated before its start date still gets the notice', function () {
    $project = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');
    Activity::withoutRecording(fn () => Activity::query()->create([
        'subject_type' => 'project',
        'subject_id' => $project->id,
        'module' => 'projects',
        'event' => 'project.status_changed',
        'changes' => ['status' => ['approved', 'active']],
        'properties' => [],
    ])->forceFill(['created_at' => '2026-10-01 10:00'])->save());

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toHaveCount(4);
});

test('projects that are not active get nothing', function (ProjectStatus $status) {
    $project = datedProject($status, '2026-10-14', '2026-10-14');

    expect(scanProjectDates('2026-10-14 06:00'))->toBe(0)
        ->and(projectDateRecipients($project, 'notifications.alerts.project_started'))->toBe([]);
})->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Closed, ProjectStatus::Archived]);

test('a disabled event sends nothing', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['project.started' => ['enabled' => false]]]);
    $project = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($project, 'notifications.alerts.project_started'))->toBe([]);
});
