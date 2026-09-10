<?php

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('guests are redirected from the user directory', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('staff roles are forbidden from the user directory', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
]);

test('administrators see the user directory in the application navigation', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSeeText('User directory')
        ->assertSee(route('admin.users.index'), false);
});

test('staff do not see the user directory in the application navigation', function () {
    $officer = User::factory()->role(UserRole::Officer)->create();

    $this->actingAs($officer)
        ->get(route('dashboard'))
        ->assertDontSee(route('admin.users.index'), false);
});

test('administrators can view and filter the user directory', function () {
    $admin = User::factory()->role(UserRole::Admin)->create(['name' => 'Directory Admin']);
    User::factory()->role(UserRole::Officer)->create(['name' => 'Visible Officer']);
    User::factory()->role(UserRole::Manager)->create(['name' => 'Hidden Manager']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSee('Visible Officer')
        ->assertSee('Hidden Manager')
        ->set('search', 'Visible')
        ->assertSee('Visible Officer')
        ->assertDontSee('Hidden Manager')
        ->set('search', '')
        ->set('role', UserRole::Manager->value)
        ->assertSee('Hidden Manager')
        ->assertDontSee('Visible Officer');
});

test('administrators can reset directory filters', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->set('search', 'Manager')
        ->set('role', UserRole::Manager->value)
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('role', '');
});

test('user names are escaped in the directory', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $unsafeName = '<script>alert("directory")</script>';
    User::factory()->create(['name' => $unsafeName]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});

test('administrators can create a verified user with any role', function (UserRole $role) {
    $admin = User::factory()->role(UserRole::Admin)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('name', 'New Staff Member')
        ->set('email', 'new.staff@example.com')
        ->set('selectedRole', $role->value)
        ->set('password', 'correct-password')
        ->set('password_confirmation', 'correct-password')
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'new.staff@example.com')->sole();

    expect($user->name)->toBe('New Staff Member')
        ->and($user->role)->toBe($role)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-password', $user->password))->toBeTrue();
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
    'admin' => UserRole::Admin,
]);

test('new user details are validated', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $existingUser = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('email', $existingUser->email)
        ->set('selectedRole', 'Super Admin')
        ->set('password', 'short')
        ->set('password_confirmation', 'different')
        ->call('saveUser')
        ->assertHasErrors(['name', 'email', 'selectedRole', 'password']);

    expect(User::query()->count())->toBe(2);
});

test('administrators can update another users details role and password', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $user = User::factory()->role(UserRole::Officer)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openEditModal', $user->id)
        ->set('name', 'Updated Manager')
        ->set('email', 'updated.manager@example.com')
        ->set('selectedRole', UserRole::Manager->value)
        ->set('password', 'updated-password')
        ->set('password_confirmation', 'updated-password')
        ->call('saveUser')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Manager')
        ->and($user->email)->toBe('updated.manager@example.com')
        ->and($user->role)->toBe(UserRole::Manager)
        ->and(Hash::check('updated-password', $user->password))->toBeTrue();
});

test('editing a user without a new password preserves the current password', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $user = User::factory()->create();
    $originalPassword = $user->password;

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openEditModal', $user->id)
        ->set('name', 'Name Only Update')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($user->refresh()->password)->toBe($originalPassword);
});

test('administrators cannot edit or delete themselves through the directory', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openEditModal', $admin->id)
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $admin->id)
        ->assertForbidden();

    $this->assertModelExists($admin);
});

test('administrators can delete an unreferenced user', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser')
        ->assertSet('showDeleteModal', false);

    $this->assertModelMissing($user);
});

test('referenced users remain when the database prevents deletion', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $user = User::factory()->create();
    Project::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser')
        ->assertSet('showDeleteModal', true)
        ->assertSee('This user is linked to operational records and cannot be deleted.');

    $this->assertModelExists($user);
});
