<?php

use App\Enums\UserRole;
use App\Models\Task;
use App\Models\User;
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

test('administrators can give an hr account access with any role', function (UserRole $role) {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $employeeId = insertHrEmployee(firstNameEn: 'Amina', lastNameEn: 'Haddad');
    $hrUserId = insertHrUser($employeeId, 'amina@example.org');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->assertSee('amina@example.org')
        ->set('hrUserId', (string) $hrUserId)
        ->set('selectedRole', $role->value)
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'amina@example.org')->sole();

    expect($user->name)->toBe('Amina Haddad')
        ->and($user->role)->toBe($role)
        ->and($user->hr_user_id)->toBe($hrUserId)
        ->and($user->employee_id)->toBe($employeeId)
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();
})->with([
    'officer' => UserRole::Officer,
    'manager' => UserRole::Manager,
    'meal' => UserRole::Meal,
    'admin' => UserRole::Admin,
]);

test('hr accounts that already have access are not offered again', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    User::factory()->create(['email' => 'taken@example.org']);
    insertHrUser(insertHrEmployee(), 'taken@example.org');
    insertHrUser(insertHrEmployee(), 'free@example.org');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal');

    expect($component->instance()->hrAccountOptions->pluck('email')->all())->toBe(['free@example.org']);
});

test('a new user must name an available hr account and a valid role', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('hrUserId', '999999')
        ->set('selectedRole', 'Super Admin')
        ->call('saveUser')
        ->assertHasErrors(['hrUserId' => 'exists', 'selectedRole']);

    expect(User::query()->count())->toBe(1);
});

test('an hr account that already has access cannot be added twice', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    User::factory()->create(['email' => 'taken@example.org']);
    $hrUserId = insertHrUser(insertHrEmployee(), 'taken@example.org');

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openCreateModal')
        ->set('hrUserId', (string) $hrUserId)
        ->call('saveUser')
        ->assertHasErrors(['hrUserId']);

    expect(User::query()->where('email', 'taken@example.org')->count())->toBe(1);
});

test('administrators can change another users role but not their hr details', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();
    $user = User::factory()->role(UserRole::Officer)->create(['name' => 'Rana Darwish', 'email' => 'rana@example.org']);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('openEditModal', $user->id)
        ->assertSet('editingUserName', 'Rana Darwish')
        ->assertSet('editingUserEmail', 'rana@example.org')
        ->assertDontSee('New password')
        ->set('selectedRole', UserRole::Manager->value)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($user->refresh())
        ->role->toBe(UserRole::Manager)
        ->name->toBe('Rana Darwish')
        ->email->toBe('rana@example.org');
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
    Task::factory()->create(['created_by' => $user->id]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('confirmUserDeletion', $user->id)
        ->call('deleteUser')
        ->assertSet('showDeleteModal', true)
        ->assertSee('This user is linked to operational records and cannot be deleted.');

    $this->assertModelExists($user);
});
