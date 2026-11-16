<?php

use App\Models\Indicator;
use App\Models\Role;
use Livewire\Livewire;

dataset('logframe abilities', [
    'results.create', 'results.update', 'results.delete',
    'indicators.create', 'indicators.update', 'indicators.delete',
    'targets.create', 'targets.update', 'targets.delete',
    'indicator-library.create', 'indicator-library.update', 'indicator-library.delete',
]);

test('a MEAL officer holds the role but cannot build the logframe', function (string $ability) {
    holdMealManagerSlot();

    expect(userWithRole('MEAL')->can($ability))->toBeFalse();
})->with('logframe abilities');

test('the MEAL manager builds the logframe', function (string $ability) {
    expect(mealManager()->can($ability))->toBeTrue();
})->with('logframe abilities');

test('nobody with the MEAL role builds the logframe while the MEAL manager slot is vacant', function () {
    expect(userWithRole('MEAL')->can('results.create'))->toBeFalse();
});

test('holding the MEAL manager slot grants nothing the role does not', function () {
    $employeeId = holdMealManagerSlot();

    expect(userWithRole('Team Member', $employeeId)->can('results.create'))->toBeFalse();
});

test('the General Manager keeps what the role grants and Managers no longer write the logframe', function () {
    expect(userWithRole('General Manager')->can('results.update'))->toBeTrue()
        ->and(userWithRole('General Manager')->can('targets.create'))->toBeFalse()
        ->and(userWithRole('Managers')->can('results.update'))->toBeFalse()
        ->and(userWithRole('Managers')->can('indicators.update'))->toBeFalse();
});

test('MEAL officers and managers still log readings but cannot open the indicator form', function (string $role) {
    $indicator = Indicator::factory()->create();
    $page = fn () => Livewire::actingAs(userWithRole($role))->test('pages::indicators.index');

    $page()->call('openProgressModal', $indicator->id)->assertOk();
    $page()->call('openEditModal', $indicator->id)->assertForbidden();
    $page()->call('openCreateModal')->assertForbidden();
})->with(['MEAL', 'Managers']);

test('the migration gives reading to whoever edited indicators and takes logframe writing from Managers', function () {
    $managers = Role::findByName('Managers', 'web');
    $managers->givePermissionTo(['indicators.create', 'indicators.update', 'results.create', 'results.update']);
    $managers->revokePermissionTo('indicators.record');

    (require glob(database_path('migrations/*_restrict_framework_writing_to_meal_manager.php'))[0])->up();

    $managers = Role::findByName('Managers', 'web');

    expect($managers->hasPermissionTo('indicators.record'))->toBeTrue()
        ->and($managers->hasAnyPermission(['indicators.create', 'indicators.update', 'results.create', 'results.update']))->toBeFalse()
        ->and(Role::findByName('Team Member', 'web')->hasPermissionTo('indicators.record'))->toBeFalse();
});
