<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| The role beside the name in the account menu (M-05): the user's active
| role, translated, read from the roles already loaded.
*/

function roleUser(array $roles, ?string $active = null): User
{
    $user = userWithRole($roles[0]);

    foreach (array_slice($roles, 1) as $role) {
        $user->assignRole(Role::findOrCreate($role));
    }

    $user->forceFill(['active_role_id' => $active === null ? null : Role::findByName($active)->id])->save();

    return $user->fresh();
}

test('the active role is the primary role when the user still holds it', function () {
    $user = roleUser(['Team Member', 'MEAL'], active: 'MEAL');

    expect($user->primaryRole()->name)->toBe('MEAL');
});

test('without an active role the highest of the held roles is shown', function () {
    $user = roleUser(['Team Member', 'Financial', 'Public Relations'], active: null);

    expect($user->primaryRole()->name)->toBe('Financial');

    $admin = roleUser(['Team Member', 'Super Admin'], active: null);

    expect($admin->primaryRole()->name)->toBe('Super Admin');
});

test('a user with no role has no primary role', function () {
    $user = userWithRole('Team Member');
    $user->syncRoles([]);

    expect($user->fresh()->primaryRole())->toBeNull();
});

test('the label is translated for the built-in roles and the stored name for a custom one', function () {
    $meal = Role::findOrCreate('Public Relations Manager');
    $custom = Role::findOrCreate('Regional Auditor');

    expect($meal->label())->toBe(trans('roles.names.public_relations_manager', [], 'en'));

    app()->setLocale('ar');

    expect($meal->label())->toBe(trans('roles.names.public_relations_manager', [], 'ar'))
        ->and($meal->label())->not->toBe('Public Relations Manager')
        ->and($custom->label())->toBe('Regional Auditor');
});

test('every built-in role has an English and an Arabic name', function () {
    foreach (['super_admin', 'general_manager', 'managers', 'meal', 'financial', 'public_relations_manager', 'public_relations', 'media_team', 'team_member'] as $slug) {
        expect(trans("roles.names.{$slug}", [], 'en'))->not->toBe("roles.names.{$slug}")
            ->and(trans("roles.names.{$slug}", [], 'ar'))->not->toBe("roles.names.{$slug}");
    }
});

test('reading the primary role costs no extra query once the roles are loaded', function () {
    $user = roleUser(['Team Member', 'MEAL'], active: 'MEAL');
    $user->load('roles');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $label = $user->primaryRole()?->label();

    expect(DB::getQueryLog())->toBe([])->and($label)->toBe('MEAL');
});

test('the account menu shows the role under the name in both languages', function () {
    $user = roleUser(['Public Relations Manager'], active: 'Public Relations Manager');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-user-role', false)
        ->assertSee(trans('roles.names.public_relations_manager', [], 'en'));

    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user->fresh())->get(route('dashboard'))
        ->assertOk()
        ->assertSee(trans('roles.names.public_relations_manager', [], 'ar'));
});
