<?php

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Result;
use App\Models\User;
use Livewire\Livewire;

dataset('framework deletes', [
    'result' => [fn (): array => ['pages::results.index', Result::factory()->create()->id, 'deleteResult', Result::class]],
    'indicator' => [fn (): array => ['pages::indicators.index', Indicator::factory()->create()->id, 'deleteIndicator', Indicator::class]],
    'target' => [fn (): array => ['pages::targets.index', PlanIndicator::factory()->create()->id, 'deleteTarget', PlanIndicator::class]],
]);

test('Team Members and Managers cannot delete framework records; MEAL can', function (Closure $case) {
    [$page, $id, $delete, $model] = $case();
    $attempt = fn (User $user) => Livewire::actingAs($user)->test($page)->call('confirmDelete', $id);

    $attempt(userWithRole('Team Member'))->assertForbidden();
    $attempt(userWithRole('Managers'))->assertForbidden();
    $attempt(userWithRole('MEAL'))->call($delete)->assertOk();

    expect($model::query()->find($id))->toBeNull();
})->with('framework deletes');

test('a Team Member cannot open any framework create form', function (string $page) {
    Livewire::actingAs(userWithRole('Team Member'))->test($page)->call('openCreateModal')->assertForbidden();
})->with(['pages::results.index', 'pages::indicators.index', 'pages::targets.index']);

test('Managers edit framework records', function () {
    $indicator = Indicator::factory()->create();

    Livewire::actingAs(userWithRole('Managers'))->test('pages::indicators.index')
        ->call('openEditModal', $indicator->id)
        ->assertOk();
});

test('recording an indicator reading needs indicators.update', function () {
    $indicator = Indicator::factory()->create();

    Livewire::actingAs(userWithRole('Team Member'))->test('pages::indicators.index')->call('openProgressModal', $indicator->id)->assertForbidden();
    Livewire::actingAs(userWithRole('MEAL'))->test('pages::indicators.index')->call('openProgressModal', $indicator->id)->assertOk();
});

test('a create deep link does nothing for someone without the permission', function () {
    Livewire::actingAs(userWithRole('Team Member'))->withQueryParams(['create' => Result::factory()->create()->id])
        ->test('pages::indicators.index')
        ->assertOk()
        ->assertSet('showIndicatorModal', false);

    Livewire::actingAs(userWithRole('Team Member'))->withQueryParams(['create' => '1'])
        ->test('pages::targets.index')
        ->assertOk()
        ->assertSet('showTargetModal', false);
});
