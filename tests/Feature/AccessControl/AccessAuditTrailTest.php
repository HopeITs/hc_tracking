<?php

use App\Models\Activity;
use App\Models\Permission;
use App\Models\Role;
use App\Notifications\ActivityPresenter;
use App\Notifications\Recipients\UserRecipients;
use Livewire\Livewire;

/*
| Who changed which role or permission, and when, is written to the
| activity log.
*/

beforeEach(function () {
    $this->superAdmin = userWithRole('Super Admin');
    $this->page = fn () => Livewire::actingAs($this->superAdmin)->test('pages::admin.users')->call('showTab', 'roles');
});

test('changing a role\'s permissions records what was added and removed', function () {
    $meal = Role::findByName('MEAL', 'web');
    $added = Permission::findByName('projects.create', 'web');
    $removed = Permission::findByName('reports.approve', 'web');
    $after = $meal->permissions->pluck('id')->reject(fn (int $id): bool => $id === $removed->id)->push($added->id)->values()->all();

    ($this->page)()
        ->call('selectRole', $meal->id)
        ->set('rolePermissionIds', $after)
        ->call('saveRolePermissions')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('event', 'role.permissions_changed')->sole();

    expect($activity->subject_type)->toBe('role')
        ->and($activity->subject_id)->toBe($meal->id)
        ->and($activity->actor_id)->toBe($this->superAdmin->id)
        ->and($activity->properties['added_permissions'])->toBe(['projects.create'])
        ->and($activity->properties['removed_permissions'])->toBe(['reports.approve'])
        ->and(app(ActivityPresenter::class)->sentence($activity, 'en'))
        ->toContain('MEAL')->toContain('projects.create')->toContain('reports.approve');
});

test('saving a role without changes records nothing', function () {
    $meal = Role::findByName('MEAL', 'web');

    ($this->page)()->call('selectRole', $meal->id)->call('saveRolePermissions');

    expect(Activity::query()->where('event', 'role.permissions_changed')->exists())->toBeFalse();
});

test('creating, renaming and deleting a role is recorded', function () {
    $page = ($this->page)()->set('newRoleName', 'Field Staff')->call('createRole');
    $role = Role::findByName('Field Staff', 'web');

    $page->set('selectedRoleName', 'Field Team')->call('renameRole')->call('deleteRole');

    expect(Activity::query()->where('subject_type', 'role')->where('subject_id', $role->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['role.created', 'role.renamed', 'role.deleted'])
        ->and(Activity::query()->where('event', 'role.renamed')->sole()->changes)->toBe(['name' => ['Field Staff', 'Field Team']]);
});

test('changing a user\'s direct permissions is recorded', function () {
    $target = userWithRole('Team Member');

    Livewire::actingAs($this->superAdmin)
        ->test('pages::admin.users')
        ->call('selectUser', $target->id)
        ->set('userPermissionIds', [Permission::findByName('projects.create', 'web')->id])
        ->call('saveUserAccess')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('event', 'user.permissions_changed')->sole();

    expect($activity->subject_id)->toBe($target->id)
        ->and($activity->properties['added_permissions'])->toBe(['projects.create'])
        ->and($activity->properties['removed_permissions'])->toBe([]);
});

test('a role change is never delivered to the user who happens to share the role\'s id', function () {
    $meal = Role::findByName('MEAL', 'web');
    $activity = $meal->recordActivity('role.created');
    $recipients = app(UserRecipients::class);

    expect($recipients->directUserIds($activity))->toBe([])
        ->and($recipients->permissionExemptUserIds($activity))->toBe([]);
});
