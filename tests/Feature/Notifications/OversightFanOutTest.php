<?php

use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\NotificationMute;
use App\Models\Project;
use App\Models\Role;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\RecipientResolver;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = userWithRole('MEAL');
    $this->generalManager = userWithRole('General Manager');
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->bystander = userWithRole('Media Team');
    $this->actor = userWithRole('Public Relations');
    $this->project = Activity::withoutRecording(fn () => Project::factory()->create(['user_id' => $this->ownerEmployee]));
});

/**
 * A project activity written by hand, so only the resolver is under test.
 */
function projectEvent(Project $project, string $event, ?int $actorId): Activity
{
    return Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actorId,
        'subject_type' => 'project',
        'subject_id' => $project->id,
        'program_id' => null,
        'module' => 'projects',
        'event' => $event,
        'changes' => [],
        'properties' => ['label' => $project->name, 'notify' => true],
    ]));
}

/**
 * @return list<int>
 */
function recipientsOf(Activity $activity): array
{
    $ids = array_keys(app(RecipientResolver::class)->resolve($activity)->priorities);
    sort($ids);

    return $ids;
}

/**
 * @param  list<int>  $ids
 * @return list<int>
 */
function sortedIds(array $ids): array
{
    sort($ids);

    return $ids;
}

test('a catalog movement reaches its direct recipient, MEAL and General Management, and nobody else', function (string $key) {
    expect(recipientsOf(projectEvent($this->project, $key, $this->actor->id)))
        ->toBe(sortedIds([$this->owner->id, $this->meal->id, $this->generalManager->id]));
})->with(['project.closed', 'project.owner_acknowledged', 'project.approved']);

test('someone who is direct and oversight at once hears once, and the actor not at all', function () {
    $dual = userWithRole('MEAL');
    Activity::withoutRecording(fn () => $dual->assignRole('General Manager'));

    expect(recipientsOf(projectEvent($this->project, 'project.closed', $this->generalManager->id)))
        ->toBe(sortedIds([$this->owner->id, $this->meal->id, $dual->id]));
});

test('a muted oversight user hears nothing, a muted direct recipient of a critical key still does', function () {
    NotificationMute::query()->create(['user_id' => $this->meal->id, 'module' => 'projects']);
    NotificationMute::query()->create(['user_id' => $this->owner->id, 'module' => 'projects']);

    expect(recipientsOf(projectEvent($this->project, 'project.approved', $this->actor->id)))
        ->toBe(sortedIds([$this->owner->id, $this->generalManager->id]));
});

test('a digest key reaches its direct recipient now and oversight only in the daily summary', function () {
    expect(recipientsOf(projectEvent($this->project, 'project.created', $this->actor->id)))->toBe([$this->owner->id]);
});

test('oversight never bypasses the module view permission', function () {
    $auditor = Activity::withoutRecording(function (): User {
        Role::query()->create(['name' => 'Auditor', 'guard_name' => 'web']);

        return User::factory()->role('Auditor')->create();
    });
    app(NotificationSettings::class)->save(['event_overrides' => ['project.closed' => ['oversight_roles' => ['Auditor']]]]);

    expect(recipientsOf(projectEvent($this->project, 'project.closed', $this->actor->id)))->not->toContain($auditor->id);
});

test('an override changes importance and adds oversight roles', function () {
    $financial = userWithRole('Financial');
    app(NotificationSettings::class)->save(['event_overrides' => [
        'project.closed' => ['importance' => 'digest'],
        'project.approved' => ['oversight_roles' => ['Financial']],
    ]]);

    expect(recipientsOf(projectEvent($this->project, 'project.closed', $this->actor->id)))->toBe([$this->owner->id])
        ->and(recipientsOf(projectEvent($this->project, 'project.approved', $this->actor->id)))
        ->toBe(sortedIds([$this->owner->id, $this->meal->id, $this->generalManager->id, $financial->id]));
});

test('a disabled event sends nothing, not even to direct recipients', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['project.closed' => ['enabled' => false]]]);

    expect(recipientsOf(projectEvent($this->project, 'project.closed', $this->actor->id)))->toBe([]);
});

test('an activity that is not a catalog movement keeps its module routing only', function () {
    expect(recipientsOf(projectEvent($this->project, 'project.dates_changed', $this->actor->id)))->toBe([$this->owner->id]);
});

test('money events reach General Management only and carry no amount', function () {
    $financial = userWithRole('Financial');
    $transfer = Activity::withoutRecording(fn () => TransfersTracking::factory()->create(['payment_amount' => '923100.12']));
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $financial->id,
        'subject_type' => 'transfer',
        'subject_id' => $transfer->id,
        'module' => 'transfers',
        'event' => 'transfer.received',
        'changes' => [],
        'properties' => ['label' => $transfer->activityLabel(), 'notify' => true],
    ]));

    FanOutActivity::dispatchSync($activity->id);

    $gmNotification = UserNotification::query()->forUser($this->generalManager)->sole();

    expect(UserNotification::query()->forUser($this->meal)->count())->toBe(0)
        ->and(json_encode($gmNotification->data))->not->toContain('923')
        ->and($gmNotification->search_text)->not->toContain('923');
});
