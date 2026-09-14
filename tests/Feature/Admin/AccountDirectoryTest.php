<?php

use App\Models\Account;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the account directory', function () {
    $this->get(route('admin.accounts.index'))->assertRedirect(route('login'));
});

test('accounts can be filtered by open and closed state', function () {
    $user = User::factory()->create();

    Account::factory()->create(['name' => 'Operations USD', 'is_active' => true]);
    Account::factory()->create(['name' => 'Legacy EUR', 'is_active' => false, 'closed_at' => '2026-01-31']);

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->assertSee('Operations USD')
        ->assertSee('Legacy EUR')
        ->set('state', 'active')
        ->assertSee('Operations USD')
        ->assertDontSee('Legacy EUR')
        ->call('clearFilters')
        ->set('state', 'closed')
        ->assertDontSee('Operations USD')
        ->assertSee('Legacy EUR');
});

test('an account can be opened', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('name', '  Reserve SYP  ')
        ->set('openedAt', '2026-02-01')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account = Account::query()->where('name', 'Reserve SYP')->sole();

    expect($account->is_active)->toBeTrue()
        ->and($account->closed_at)->toBeNull();
});

test('opening an account requires a name and an opening date', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('openedAt', '')
        ->call('saveAccount')
        ->assertHasErrors(['name', 'openedAt']);
});

test('closing an account sets both the flag and the date together', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('confirmClose', $account->id)
        ->call('closeAccount')
        ->assertHasNoErrors();

    $account->refresh();

    expect($account->is_active)->toBeFalse()
        ->and($account->closed_at)->not->toBeNull();
});

test('accounts offer no delete because transfers restrict it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create();
    TransfersTracking::factory()->create(['account_id' => $account->id]);

    $component = Livewire::actingAs($user)->test('pages::admin.accounts');

    $component->assertDontSee('wire:click="confirmDelete');

    expect(method_exists($component->instance(), 'deleteAccount'))->toBeFalse()
        ->and(DB::table('accounts')->find($account->id))->not->toBeNull();
});

test('the account directory renders with no accounts', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::admin.accounts')->assertSee('No accounts yet');
});
