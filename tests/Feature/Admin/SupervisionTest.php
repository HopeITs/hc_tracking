<?php

use App\Actions\Supervisions\AddSupervision;
use App\Actions\Supervisions\RemoveSupervision;
use App\Models\Activity;
use App\Models\Supervision;
use App\Models\User;
use App\Notifications\ActivityPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Run an add and return its validation errors, keyed by field.
 *
 * @return array<string, list<string>>
 */
function supervisionErrors(int $supervisorId, int $superviseeId): array
{
    try {
        app(AddSupervision::class)->handle($supervisorId, $superviseeId);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * A local supervision row written without recording, for setup.
 */
function storedSupervision(int $supervisorId, int $superviseeId): Supervision
{
    return Activity::withoutRecording(fn (): Supervision => Supervision::factory()->create([
        'supervisor_employee_id' => $supervisorId,
        'supervisee_employee_id' => $superviseeId,
    ]));
}

/**
 * The supervisee holds an active contract on a position whose direct-manager
 * position the supervisor holds.
 */
function hrReportsTo(int $superviseeId, int $managerId): void
{
    $department = insertHrDepartment();
    $managerPosition = insertHrPosition($department, 'Manager');
    insertHrContract($managerId, $managerPosition);
    insertHrContract($superviseeId, insertHrPosition($department, 'Officer', directManagerId: $managerPosition));
}

test('adding a supervision stores who added it and records an activity naming both people', function () {
    $admin = userWithRole('General Manager');
    $supervisor = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali', firstNameAr: 'نور', lastNameAr: 'علي');
    $supervisee = insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh', firstNameAr: 'رامي', lastNameAr: 'صالح');

    $this->actingAs($admin);
    app(AddSupervision::class)->handle($supervisor, $supervisee);

    $row = DB::table('supervisions')->sole();
    $activity = Activity::query()->sole();

    expect($row->supervisor_employee_id)->toBe($supervisor)
        ->and($row->supervisee_employee_id)->toBe($supervisee)
        ->and($row->created_by)->toBe($admin->id)
        ->and($row->pair_low)->toBe(min($supervisor, $supervisee))
        ->and($row->pair_high)->toBe(max($supervisor, $supervisee))
        ->and($activity->event)->toBe('supervision.added')
        ->and($activity->module)->toBe('access-management')
        ->and($activity->label())->toBe('Nour Ali → Rami Saleh')
        ->and($activity->properties['supervisor'])->toEqual(['en' => 'Nour Ali', 'ar' => 'نور علي'])
        ->and($activity->properties['supervisee'])->toEqual(['en' => 'Rami Saleh', 'ar' => 'رامي صالح']);
});

test('a person may have several supervisors', function () {
    $this->actingAs(userWithRole('General Manager'));
    $supervisee = insertHrEmployee();

    app(AddSupervision::class)->handle(insertHrEmployee(), $supervisee);
    app(AddSupervision::class)->handle(insertHrEmployee(), $supervisee);

    expect(DB::table('supervisions')->where('supervisee_employee_id', $supervisee)->count())->toBe(2);
});

test('an add is refused with the matching message and writes nothing', function (Closure $scenario, string $field, string $message) {
    $this->actingAs(userWithRole('General Manager'));
    [$supervisor, $supervisee] = $scenario();
    $rowsBefore = DB::table('supervisions')->count();

    expect(supervisionErrors($supervisor, $supervisee))->toBe([$field => [$message]])
        ->and(DB::table('supervisions')->count())->toBe($rowsBefore)
        ->and(Activity::query()->where('event', 'supervision.added')->count())->toBe(0);
})->with([
    'self' => [function (): array {
        $person = insertHrEmployee();

        return [$person, $person];
    }, 'superviseeEmployeeId', 'A person cannot supervise themselves'],
    'inactive supervisor' => [fn (): array => [insertHrEmployee(isActive: false), insertHrEmployee()], 'supervisorEmployeeId', 'Choose an active employee'],
    'inactive supervisee' => [fn (): array => [insertHrEmployee(), insertHrEmployee(isActive: false)], 'superviseeEmployeeId', 'Choose an active employee'],
    'unknown employee' => [fn (): array => [insertHrEmployee(), 999_999], 'superviseeEmployeeId', 'Choose an active employee'],
    'duplicate' => [function (): array {
        [$supervisor, $supervisee] = [insertHrEmployee(), insertHrEmployee()];
        storedSupervision($supervisor, $supervisee);

        return [$supervisor, $supervisee];
    }, 'superviseeEmployeeId', 'This supervision already exists'],
    'reverse pair' => [function (): array {
        [$first, $second] = [insertHrEmployee(), insertHrEmployee()];
        storedSupervision($first, $second);

        return [$second, $first];
    }, 'superviseeEmployeeId', 'Two people cannot supervise each other'],
    'repeats the HR direct manager' => [function (): array {
        [$manager, $officer] = [insertHrEmployee(), insertHrEmployee()];
        hrReportsTo($officer, $manager);

        return [$manager, $officer];
    }, 'superviseeEmployeeId', 'This supervision already comes from HR'],
    'reverses the HR direct manager' => [function (): array {
        [$manager, $officer] = [insertHrEmployee(), insertHrEmployee()];
        hrReportsTo($officer, $manager);

        return [$officer, $manager];
    }, 'superviseeEmployeeId', 'Two people cannot supervise each other'],
]);

test('a second identical add leaves one row', function () {
    $this->actingAs(userWithRole('General Manager'));
    [$supervisor, $supervisee] = [insertHrEmployee(), insertHrEmployee()];

    app(AddSupervision::class)->handle($supervisor, $supervisee);

    expect(supervisionErrors($supervisor, $supervisee))->toBe(['superviseeEmployeeId' => ['This supervision already exists']])
        ->and(DB::table('supervisions')->count())->toBe(1);
});

test('the reverse pair committed by another admin between the check and the insert is refused, not a 500', function () {
    $this->actingAs(userWithRole('General Manager'));
    [$first, $second] = [insertHrEmployee(), insertHrEmployee()];

    // Another admin's row lands after AddSupervision looked for the pair.
    Supervision::creating(function () use ($first, $second): void {
        DB::table('supervisions')->insert(['supervisor_employee_id' => $second, 'supervisee_employee_id' => $first]);
    });

    // The simulated row shares the action's transaction, so the refusal rolls it back too.
    expect(supervisionErrors($first, $second))->toBe(['superviseeEmployeeId' => ['Two people cannot supervise each other']])
        ->and(DB::table('supervisions')->where('supervisor_employee_id', $first)->exists())->toBeFalse();
});

test('the database refuses a reverse pair on its own', function () {
    [$first, $second] = [insertHrEmployee(), insertHrEmployee()];
    DB::table('supervisions')->insert(['supervisor_employee_id' => $first, 'supervisee_employee_id' => $second]);

    expect(fn () => DB::table('supervisions')->insert(['supervisor_employee_id' => $second, 'supervisee_employee_id' => $first]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('removing a supervision deletes it and records the removal; a second remove is a no-op', function () {
    $this->actingAs(userWithRole('General Manager'));
    $supervision = storedSupervision(insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali'), insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh'));

    expect(app(RemoveSupervision::class)->handle($supervision->id))->toBeTrue()
        ->and(DB::table('supervisions')->count())->toBe(0)
        ->and(Activity::query()->sole()->event)->toBe('supervision.removed')
        ->and(Activity::query()->sole()->label())->toBe('Nour Ali → Rami Saleh');

    expect(app(RemoveSupervision::class)->handle($supervision->id))->toBeFalse()
        ->and(Activity::query()->count())->toBe(1);
});

test('managing supervisions needs supervisions.manage', function () {
    $supervision = storedSupervision(insertHrEmployee(), insertHrEmployee());
    $this->actingAs(Activity::withoutRecording(fn (): User => User::factory()->create()));

    expect(fn () => app(AddSupervision::class)->handle(insertHrEmployee(), insertHrEmployee()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveSupervision::class)->handle($supervision->id))->toThrow(AuthorizationException::class)
        ->and(DB::table('supervisions')->count())->toBe(1);
});

test('a role without supervisions.manage is refused', function () {
    $this->actingAs(userWithRole('MEAL'));

    expect(fn () => app(AddSupervision::class)->handle(insertHrEmployee(), insertHrEmployee()))->toThrow(AuthorizationException::class);
});

/**
 * An account that may open Users & Roles but not manage supervisions.
 */
function userDirectoryOnlyAdmin(): User
{
    $user = userWithRole('Team Member');
    $user->givePermissionTo('access-management.manage-users');

    return $user;
}

test('the Supervision tab is offered only with supervisions.manage', function () {
    Livewire::actingAs(userWithRole('General Manager'))->test('pages::admin.users')
        ->assertSee(__('users.supervision_tab'))
        ->call('showTab', 'supervision')
        ->assertSet('activeTab', 'supervision')
        ->assertSeeLivewire('admin.supervisions');

    Livewire::actingAs(userDirectoryOnlyAdmin())->test('pages::admin.users')
        ->assertDontSee(__('users.supervision_tab'))
        ->call('showTab', 'supervision')
        ->assertForbidden();
});

test('every Supervision tab method is refused without supervisions.manage', function (string $method, array $arguments) {
    $supervision = storedSupervision(insertHrEmployee(), insertHrEmployee());
    $component = Livewire::actingAs(userWithRole('General Manager'))->test('admin.supervisions');
    $this->actingAs(userDirectoryOnlyAdmin());

    $component->set('supervisorEmployeeId', (string) insertHrEmployee())
        ->set('superviseeEmployeeId', (string) insertHrEmployee())
        ->call($method, ...($arguments === [] ? [] : [$supervision->id]))
        ->assertForbidden();

    expect(DB::table('supervisions')->pluck('id')->all())->toBe([$supervision->id]);
})->with([
    'open add' => ['openAddModal', []],
    'add' => ['addSupervision', []],
    'confirm remove' => ['confirmRemove', ['id']],
]);

test('the Supervision tab cannot be mounted without supervisions.manage', function () {
    Livewire::actingAs(userDirectoryOnlyAdmin())->test('admin.supervisions')->assertForbidden();
});

test('removing through the tab needs supervisions.manage at the moment of removal', function () {
    $supervision = storedSupervision(insertHrEmployee(), insertHrEmployee());
    $component = Livewire::actingAs(userWithRole('General Manager'))->test('admin.supervisions')
        ->call('confirmRemove', $supervision->id);
    $this->actingAs(userDirectoryOnlyAdmin());

    $component->call('removeSupervision')->assertForbidden();

    expect(DB::table('supervisions')->count())->toBe(1);
});

test('General Manager adds and removes a supervision from the tab', function () {
    [$supervisor, $supervisee] = [insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali'), insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh')];

    $component = Livewire::actingAs(userWithRole('General Manager'))->test('admin.supervisions')
        ->call('openAddModal')
        ->set('supervisorEmployeeId', (string) $supervisor)
        ->set('superviseeEmployeeId', (string) $supervisee)
        ->call('addSupervision')
        ->assertHasNoErrors()
        ->assertSet('showAddModal', false)
        ->assertSee('Nour Ali')
        ->assertSee('Rami Saleh');

    $id = (int) DB::table('supervisions')->sole()->id;

    $component->call('confirmRemove', $id)
        ->assertSet('showRemoveModal', true)
        ->call('removeSupervision')
        ->assertSet('showRemoveModal', false)
        ->assertDontSee('Rami Saleh');

    expect(DB::table('supervisions')->count())->toBe(0);
});

test('a refused add from the tab shows the message on the field', function () {
    $person = insertHrEmployee();

    Livewire::actingAs(userWithRole('General Manager'))->test('admin.supervisions')
        ->call('openAddModal')
        ->set('supervisorEmployeeId', (string) $person)
        ->set('superviseeEmployeeId', (string) $person)
        ->call('addSupervision')
        ->assertHasErrors(['superviseeEmployeeId'])
        ->assertSee('A person cannot supervise themselves');

    expect(DB::table('supervisions')->count())->toBe(0);
});

test('removing a row another admin already removed is a message, not an error', function () {
    $supervision = storedSupervision(insertHrEmployee(), insertHrEmployee());
    $component = Livewire::actingAs(userWithRole('General Manager'))->test('admin.supervisions')
        ->call('confirmRemove', $supervision->id);
    Activity::withoutRecording(fn () => $supervision->delete());

    $component->call('removeSupervision')
        ->assertOk()
        ->assertSet('showRemoveModal', false);
});

test('HR relations are listed read-only as From HR and inactive local rows are marked', function () {
    [$manager, $officer] = [insertHrEmployee(firstNameEn: 'Hala', lastNameEn: 'Manager'), insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Officer')];
    hrReportsTo($officer, $manager);
    $local = storedSupervision(insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Lead'), insertHrEmployee(firstNameEn: 'Gone', lastNameEn: 'Person', isActive: false));

    $component = Livewire::actingAs(userWithRole('General Manager'))->test('admin.supervisions');

    expect(substr_count($component->html(), 'wire:click="confirmRemove('))->toBe(1);

    $component->assertSeeInOrder(['Hala Manager', 'Omar Officer', __('users.supervision_from_hr')])
        ->assertSeeHtml('data-supervision-hr="'.$manager.'-'.$officer.'"')
        ->assertSeeHtml('wire:click="confirmRemove('.$local->id.')"')
        ->assertSeeInOrder(['Gone Person', __('users.supervision_inactive')])
        ->set('search', 'Omar')
        ->assertSee('Omar Officer')
        ->assertDontSee('Gone Person');
});

test('the added and removed activities read with both names in the reader\'s language', function () {
    $admin = userWithRole('General Manager');
    $this->actingAs($admin);
    $supervisor = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali', firstNameAr: 'نور', lastNameAr: 'علي');
    $supervisee = insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Saleh', firstNameAr: 'رامي', lastNameAr: 'صالح');

    $supervision = app(AddSupervision::class)->handle($supervisor, $supervisee);
    app(RemoveSupervision::class)->handle($supervision->id);

    [$added, $removed] = Activity::query()->orderBy('id')->get()->all();
    $presenter = app(ActivityPresenter::class);

    expect($presenter->sentence($added, 'en'))->toBe($admin->name.' made Nour Ali a supervisor of Rami Saleh')
        ->and($presenter->sentence($removed, 'en'))->toBe($admin->name.' removed Nour Ali as a supervisor of Rami Saleh')
        ->and($presenter->sentence($added, 'ar'))->toContain('نور علي')->toContain('رامي صالح')
        ->and($presenter->sentence($removed, 'ar'))->toContain('نور علي')->toContain('رامي صالح');
});
