<?php

use App\Models\Activity;
use App\Models\NotificationMute;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\OversightDigestScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

// 2026-10-14 is a Wednesday; 2026-10-17/18 is a weekend (Mon–Fri default).
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = userWithRole('MEAL');
    $this->generalManager = userWithRole('General Manager');
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->actor = userWithRole('Public Relations');
    $this->project = Activity::withoutRecording(fn () => Project::factory()->create(['user_id' => $this->ownerEmployee]));
});

/**
 * An activity written by hand at a fixed time, so only the scanner is under test.
 *
 * @param  array<string, mixed>  $properties
 */
function movementAt(string $event, string $module, string $at, ?int $actorId, array $properties = [], string $subjectType = 'project', ?int $subjectId = null): void
{
    Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actorId,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId ?? test()->project->id,
        'module' => $module,
        'event' => $event,
        'changes' => [],
        'properties' => ['label' => 'Water Network', ...$properties],
    ])->forceFill(['created_at' => $at])->save());
}

function scanOversightDigest(string $at): int
{
    return app(OversightDigestScanner::class)->scan(CarbonImmutable::parse($at));
}

/**
 * @return list<UserNotification>
 */
function oversightDigestsOf(User $user): array
{
    return UserNotification::query()->forUser($user)->where('data->key', 'notifications.digests.oversight')->get()->all();
}

test('yesterday\'s digest movements become one summary per oversight user with counts per event', function () {
    movementAt('project.created', 'projects', '2026-10-13 09:00', $this->actor->id);
    movementAt('project.created', 'projects', '2026-10-13 15:00', $this->actor->id);
    movementAt('project.sectors_changed', 'projects', '2026-10-13 16:00', $this->actor->id);

    expect(scanOversightDigest('2026-10-14 06:00'))->toBe(2)
        ->and(sentencesFor($this->meal))->toBe(['Oversight summary since Oct 13, 2026: 2 × Project created, 1 × Project sectors changed (total 3)'])
        ->and(oversightDigestsOf($this->generalManager))->toHaveCount(1)
        ->and(oversightDigestsOf($this->owner))->toBe([])
        ->and(oversightDigestsOf($this->actor))->toBe([]);

    $digest = oversightDigestsOf($this->meal)[0];
    expect($digest->kind->value)->toBe('digest')
        ->and($digest->module)->toBe('system')
        ->and(array_keys($digest->data['params']))->toEqualCanonicalizing(['count', 'date', 'counts']);
});

test('immediate events and movements outside the window are not counted', function () {
    movementAt('project.closed', 'projects', '2026-10-13 09:00', $this->actor->id);
    movementAt('project.created', 'projects', '2026-10-12 23:59', $this->actor->id);
    movementAt('project.created', 'projects', '2026-10-14 00:01', $this->actor->id);

    expect(scanOversightDigest('2026-10-14 06:00'))->toBe(0)
        ->and(oversightDigestsOf($this->meal))->toBe([]);
});

test('a second run sends nothing more', function () {
    movementAt('project.created', 'projects', '2026-10-13 09:00', $this->actor->id);

    expect(scanOversightDigest('2026-10-14 06:00'))->toBe(2)
        ->and(scanOversightDigest('2026-10-14 09:00'))->toBe(0);
});

test('the first working day after a weekend covers the whole weekend, and the weekend sends nothing', function () {
    movementAt('project.created', 'projects', '2026-10-16 09:00', $this->actor->id);
    movementAt('project.created', 'projects', '2026-10-17 09:00', $this->actor->id);

    expect(scanOversightDigest('2026-10-18 06:00'))->toBe(0)
        ->and(scanOversightDigest('2026-10-19 06:00'))->toBe(2)
        ->and(sentencesFor($this->meal))->toBe(['Oversight summary since Oct 16, 2026: 2 × Project created (total 2)']);
});

test('an oversight user is not told about their own movements', function () {
    movementAt('project.created', 'projects', '2026-10-13 09:00', $this->generalManager->id);

    scanOversightDigest('2026-10-14 06:00');

    expect(oversightDigestsOf($this->generalManager))->toBe([])
        ->and(oversightDigestsOf($this->meal))->toHaveCount(1);
});

test('a muted module and a module the user cannot view are left out', function () {
    NotificationMute::query()->create(['user_id' => $this->generalManager->id, 'module' => 'projects']);
    movementAt('project.created', 'projects', '2026-10-13 09:00', $this->actor->id);
    movementAt('proposal.created', 'proposals', '2026-10-13 10:00', $this->actor->id, [], 'proposal', 1);

    scanOversightDigest('2026-10-14 06:00');

    expect(sentencesFor($this->meal))->toBe(['Oversight summary since Oct 13, 2026: 1 × Project created (total 1)'])
        ->and(sentencesFor($this->generalManager))->toBe(['Oversight summary since Oct 13, 2026: 1 × Proposal created (total 1)']);
});

test('an override to immediate takes the event out of the summary', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['project.created' => ['importance' => 'immediate']]]);
    movementAt('project.created', 'projects', '2026-10-13 09:00', $this->actor->id);

    expect(scanOversightDigest('2026-10-14 06:00'))->toBe(0);
});
