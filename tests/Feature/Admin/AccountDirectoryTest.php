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
    $user = User::factory()->role('Financial')->create();

    Account::factory()->create(['account_name' => 'Operations USD', 'is_active' => true]);
    Account::factory()->create(['account_name' => 'Legacy EUR', 'is_active' => false, 'closed_at' => '2026-01-31']);

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
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('accountName', '  Reserve SYP  ')
        ->set('bankName', 'Fransbank')
        ->set('currency', 'SYP')
        ->set('accountNumber', '21.13.0000803.11')
        ->set('country', 'Syria')
        ->set('openedAt', '2026-02-01')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account = Account::query()->where('account_name', 'Reserve SYP')->sole();

    expect($account->is_active)->toBeTrue()
        ->and($account->closed_at)->toBeNull()
        ->and($account->bank_name)->toBe('Fransbank');
});

test('an account can be opened with no known opening date', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('accountName', 'Cash Account')
        ->set('bankName', 'Cash')
        ->set('currency', 'USD')
        ->set('accountNumber', 'CASH-USD-01')
        ->set('country', 'Syria')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $account = Account::query()->where('account_name', 'Cash Account')->sole();

    expect($account->opened_at)->toBeNull();
});

test('opening an account requires the core banking fields', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->call('openCreateModal')
        ->set('accountName', '')
        ->set('bankName', '')
        ->set('accountNumber', '')
        ->set('country', '')
        ->call('saveAccount')
        ->assertHasErrors(['accountName', 'bankName', 'accountNumber', 'country']);
});

test('closing an account sets both the flag and the date together', function () {
    $user = User::factory()->role('Financial')->create();
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
    $user = User::factory()->role('Financial')->create();
    $account = Account::factory()->create();
    TransfersTracking::factory()->create(['account_id' => $account->id]);

    $component = Livewire::actingAs($user)->test('pages::admin.accounts');

    $component->assertDontSee('wire:click="confirmDelete');

    expect(method_exists($component->instance(), 'deleteAccount'))->toBeFalse()
        ->and(DB::table('accounts')->find($account->id))->not->toBeNull();
});

test('the account directory renders with no accounts', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)->test('pages::admin.accounts')->assertSee('No accounts yet');
});

test('accounts can be searched by part of their encrypted account number', function () {
    $user = User::factory()->role('Financial')->create();

    Account::factory()->create(['account_name' => 'Operations USD', 'bank_name' => 'Fransbank', 'account_number' => 'NRA201000409112768']);
    Account::factory()->create(['account_name' => 'Legacy EUR', 'bank_name' => 'Bank Audi', 'account_number' => '4040Z06926201']);

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->set('search', 'nra2010004')
        ->assertSee('Operations USD')
        ->assertDontSee('Legacy EUR')
        ->set('search', 'Audi')
        ->assertSee('Legacy EUR')
        ->assertDontSee('Operations USD');
});

test('the directory finds an account by the last four characters of its number', function () {
    $user = User::factory()->role('Financial')->create();

    Account::factory()->create(['account_name' => 'Ends Eleven', 'account_number' => '21.13.0000803.11']);
    Account::factory()->create(['account_name' => 'Ends Other', 'account_number' => '5550001234']);

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->set('search', '0311')
        ->assertSee('Ends Eleven')
        ->assertDontSee('Ends Other')
        ->set('search', '21.13.0000803.11')
        ->assertSee('Ends Eleven')
        ->assertDontSee('Ends Other');
});

test('the account form is rendered only while its modal is open', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::admin.accounts')
        ->assertDontSeeHtml('wire:model="accountNumber"')
        ->call('openCreateModal')
        ->assertSeeHtml('wire:model="accountNumber"');
});
