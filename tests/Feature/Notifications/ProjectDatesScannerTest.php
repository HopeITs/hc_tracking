<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
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
 * A place of the shared program, in the given status, with fixed dates: the
 * location row the scanner reads (Y-16, D-93).
 *
 * @param  array<string, mixed>  $attributes  passed to Project::factory()
 */
function datedProject(ProjectStatus $status, string $start, string $end, array $attributes = []): ProposalLocation
{
    return Activity::withoutRecording(function () use ($status, $start, $end, $attributes): ProposalLocation {
        $project = Project::factory()->status($status)->create([
            'program_id' => test()->program->id,
            'user_id' => test()->ownerEmployee,
            'start_date' => $start,
            'end_date' => $end,
            'name' => 'Water Network',
            ...$attributes,
        ]);

        return rowFor($project);
    });
}

function scanProjectDates(string $at): int
{
    return app(ProjectDatesScanner::class)->scan(CarbonImmutable::parse($at));
}

/**
 * @return list<int> user ids holding a notification with this key on this place
 */
function projectDateRecipients(ProposalLocation $row, string $key): array
{
    $ids = UserNotification::query()
        ->where('data->key', $key)
        ->where('subject_type', 'proposal_location')
        ->where('subject_id', $row->id)
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

test('an active place announces its start to the owner, program manager, MEAL and General Management', function () {
    $row = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($row, 'notifications.alerts.project_started'))
        ->toBe(sortedUserIds([$this->owner->id, $this->programManager->id, $this->meal->id, $this->generalManager->id]))
        // The sentence names the row's proposal (Y-16).
        ->and(sentencesFor($this->owner))->toBe(['The project “'.$row->headline().'” started on Oct 14, 2026']);
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
    $row = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');
    scanProjectDates('2026-10-14 06:00');

    Activity::withoutRecording(fn () => $row->forceFill(['start_date' => '2026-10-15'])->save());
    scanProjectDates('2026-10-15 06:00');

    expect(UserNotification::query()->forUser($this->owner)->where('data->key', 'notifications.alerts.project_started')->count())->toBe(2);
});

test('a place activated after its start date gets no start notice', function (string $event, array $changes) {
    $row = datedProject(ProjectStatus::Active, '2026-10-17', '2027-06-30');
    Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null,
        'subject_type' => 'proposal_location',
        'subject_id' => $row->id,
        'module' => 'projects',
        'event' => $event,
        'changes' => $changes,
        'properties' => [],
    ])->forceFill(['created_at' => '2026-10-19 05:00'])->save());

    scanProjectDates('2026-10-19 06:00');

    expect(projectDateRecipients($row, 'notifications.alerts.project_started'))->toBe([]);
})->with([
    'activation event' => ['project.activated', []],
    'status change to active' => ['project.status_changed', ['status' => ['approved', 'active']]],
]);

test('a place activated before its start date still gets the notice', function () {
    $row = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');
    Activity::withoutRecording(fn () => Activity::query()->create([
        'subject_type' => 'proposal_location',
        'subject_id' => $row->id,
        'module' => 'projects',
        'event' => 'project.status_changed',
        'changes' => ['status' => ['approved', 'active']],
        'properties' => [],
    ])->forceFill(['created_at' => '2026-10-01 10:00'])->save());

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($row, 'notifications.alerts.project_started'))->toHaveCount(4);
});

test('places that are not active get nothing', function (ProjectStatus $status) {
    $row = datedProject($status, '2026-10-14', '2026-10-14');

    expect(scanProjectDates('2026-10-14 06:00'))->toBe(0)
        ->and(projectDateRecipients($row, 'notifications.alerts.project_started'))->toBe([]);
})->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Closed, ProjectStatus::Archived]);

test('a disabled event sends nothing', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['project.started' => ['enabled' => false]]]);
    $row = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');

    scanProjectDates('2026-10-14 06:00');

    expect(projectDateRecipients($row, 'notifications.alerts.project_started'))->toBe([]);
});

/*
| Y-16 (D-77): two proposals may deliver at one place. A project produced one
| start notice; two rows must not produce two for the same person and day.
*/

test('two places of one city and program announce their start once per person', function () {
    $first = datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');
    $second = Activity::withoutRecording(function () use ($first): ProposalLocation {
        $project = Project::factory()->status(ProjectStatus::Active)->create([
            'program_id' => test()->program->id,
            'user_id' => test()->ownerEmployee,
            'city_id' => $first->city_id,
            'start_date' => '2026-10-14',
            'end_date' => '2027-06-30',
            'name' => 'Water Network Phase Two',
        ]);

        return rowFor($project);
    });

    $sent = scanProjectDates('2026-10-14 06:00');

    expect($first->place()->key())->toBe($second->place()->key())
        ->and($sent)->toBe(4)
        ->and(UserNotification::query()->forUser($this->owner)->where('data->key', 'notifications.alerts.project_started')->count())->toBe(1);
});

test('two places in different cities each announce their own start', function () {
    datedProject(ProjectStatus::Active, '2026-10-14', '2027-06-30');
    Activity::withoutRecording(function (): ProposalLocation {
        $project = Project::factory()->status(ProjectStatus::Active)->create([
            'program_id' => test()->program->id,
            'user_id' => test()->ownerEmployee,
            'city_id' => insertHrCity('Daraa'),
            'start_date' => '2026-10-14',
            'end_date' => '2027-06-30',
            'name' => 'Daraa Water Network',
        ]);

        return rowFor($project);
    });

    scanProjectDates('2026-10-14 06:00');

    expect(UserNotification::query()->forUser($this->owner)->where('data->key', 'notifications.alerts.project_started')->count())->toBe(2);
});
