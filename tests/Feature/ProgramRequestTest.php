<?php

use App\Actions\Proposals\SaveProposal;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Proposal;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\RecipientResolver;
use App\Support\Slots;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| W-34a slice 2 (contracts §15.2): a program request opened by a proposal
| row reaches the HR liaison slot holder and the Public Relations Manager;
| the request being linked to its HR program reaches the proposal owner.
| The HR liaison is a position (contracts §9), resolved by Slots like the
| reviewer slots, and hears about the request even without proposals.view.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
});

/**
 * Point the HR liaison slot at a position held by a new employee with an
 * account in the given role, and return that user.
 */
function hrLiaison(string $role = 'Team Member'): User
{
    $employeeId = insertHrEmployee(firstNameEn: 'Liaison', lastNameEn: 'Holder');
    $positionId = insertHrPosition(insertHrDepartment('Human Resources'), 'HR Liaison');
    insertHrContract($employeeId, $positionId);
    app(NotificationSettings::class)->save(['hr_liaison_position' => $positionId]);

    return userWithRole($role, $employeeId);
}

/**
 * @return list<string>
 */
function programRequestKeysOf(User $user): array
{
    return UserNotification::query()->forUser($user)->orderBy('created_at')->get()->pluck('data.key')->all();
}

/**
 * Every activity id delivered to the user's inbox, across rows. A watcher who
 * already holds an unread row for the same actor and proposal has the event
 * merged into it (NotificationGrouper), so the row's headline key may be a
 * later same-rank event; activity_ids is the delivery probe that survives that.
 *
 * @return list<int>
 */
function deliveredActivityIdsOf(User $user): array
{
    return UserNotification::query()->forUser($user)->get()->flatMap(fn (UserNotification $row): array => $row->data['activity_ids'] ?? [])->all();
}

function programRequestOpenedActivityId(): int
{
    return Activity::query()->where('event', 'program_request.opened')->sole()->id;
}

/**
 * A proposal created with one new row that names a program HR does not have.
 */
function proposalNeedingProgram(User $actor): Proposal
{
    return app(SaveProposal::class)->handle(null, [
        'name' => 'Winter Shelter Response',
        'internalProjectNumber' => 'HC-2026-953',
        'donorId' => Donor::factory()->create()->id,
        'locations' => [[
            'city_id' => test()->aleppo,
            'center_ids' => [test()->aleppoCenter],
            'is_new' => true,
            'new_program_name_en' => 'Alternative Energy',
            'new_program_name_ar' => 'الطاقة البديلة',
        ]],
        'beneficiaries' => [],
        'plannedDateBegin' => '2026-11-01',
        'plannedDateEnd' => '2027-10-31',
        'notes' => '',
        'startingStage' => 'concept note',
        'skipReason' => null,
    ], $actor);
}

test('the HR liaison slot resolves to the holder of its position and is vacant when unset', function () {
    expect(Slots::holderOf('hr_liaison'))->toBeNull();

    $liaison = hrLiaison();

    expect(Slots::holderOf('hr_liaison'))->toBe($liaison->employee_id);
});

test('opening a program request tells the HR liaison and the Public Relations Manager', function () {
    $liaison = hrLiaison();
    $prManager = userWithRole('Public Relations Manager');
    $actor = userWithRole('Financial');
    $this->actingAs($actor);

    proposalNeedingProgram($actor);

    $opened = programRequestOpenedActivityId();

    expect(programRequestKeysOf($liaison))->toContain('notifications.events.program_request.opened')
        ->and(deliveredActivityIdsOf($liaison))->toContain($opened)
        ->and(deliveredActivityIdsOf($prManager))->toContain($opened)
        ->and(deliveredActivityIdsOf($actor))->not->toContain($opened);
});

test('the opened request names the typed program and never an amount', function () {
    $liaison = hrLiaison();
    $actor = userWithRole('Financial');
    $this->actingAs($actor);

    proposalNeedingProgram($actor);

    $notification = UserNotification::query()->forUser($liaison)->get()->first(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.program_request.opened');

    expect($notification)->not->toBeNull()
        ->and($notification->data['params']['name'] ?? null)->toBe('Alternative Energy')
        ->and(json_encode($notification->data))->not->toContain('requested_budget');
});

test('the linked request reaches the proposal owner, who needs no proposals.view for it', function () {
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create());
    // Y-17: the proposal's people are the responsibles of its places.
    $owner = Activity::withoutRecording(function () use ($proposal): User {
        $place = approvablePlace($proposal);

        return User::factory()->create(['employee_id' => $place->responsible_employee_id]);
    });
    $actor = userWithRole('Managers');

    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id,
        'subject_type' => 'proposal',
        'subject_id' => $proposal->id,
        'module' => 'proposals',
        'event' => 'program_request.linked',
        'changes' => [],
        'properties' => ['label' => $proposal->name, 'notify' => true, 'name' => 'Alternative Energy'],
    ]));

    $resolved = app(RecipientResolver::class)->resolve($activity);

    expect($resolved->priorities)->toHaveKey($owner->id)
        ->and($resolved->priorities[$owner->id])->toBe(NotificationPriority::Info);
});
