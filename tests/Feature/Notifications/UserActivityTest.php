<?php

use App\Models\Activity;
use App\Models\HrUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Livewire\Livewire;

test('changing roles tells the affected user even without user-management permission, and super admins', function () {
    $superAdmin = userWithRole('Super Admin');
    $affected = Activity::withoutRecording(fn () => userWithRole('Media Team'));

    Livewire::actingAs($superAdmin)->test('pages::admin.users')
        ->call('selectUser', $affected->id)
        ->set('userRoleIds', [Role::findByName('MEAL')->id])
        ->call('saveUserAccess');

    expect(Activity::query()->sole()->event)->toBe('user.role_changed')
        ->and(sentencesFor($affected)[0])->toContain('MEAL');
});

test('saving access without a role change records nothing', function () {
    $superAdmin = userWithRole('Super Admin');
    $affected = Activity::withoutRecording(fn () => userWithRole('MEAL'));

    Livewire::actingAs($superAdmin)->test('pages::admin.users')
        ->call('selectUser', $affected->id)
        ->call('saveUserAccess');

    expect(Activity::query()->count())->toBe(0);
});

test('a login refreshing the user name records nothing', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->forceFill(['name' => 'New Name'])->save();

    expect(Activity::query()->count())->toBe(0);
});

test('creating an account from hr records the account and its role; super admins hear about it', function () {
    $superAdmin = userWithRole('Super Admin');
    $hrUserId = insertHrUser(insertHrEmployee(), 'nour@example.org');

    $this->actingAs($superAdmin);
    User::createFromHrAccount(HrUser::query()->findOrFail($hrUserId), 'MEAL');

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['user.created', 'user.role_changed'])
        ->and(UserNotification::query()->forUser($superAdmin)->count())->toBe(0); // actor
});
