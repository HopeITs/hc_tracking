<?php

use App\Models\Account;
use App\Models\Donor;
use App\Models\Proposal;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('a viewer who holds only accounts.view sees accounts but cannot open, edit or close one', function () {
    $account = Account::factory()->create(['is_active' => true]);
    $member = userWithRole('Team Member')->givePermissionTo('accounts.view');
    $page = fn () => Livewire::actingAs($member)->test('pages::admin.accounts');

    $page()->assertOk()
        ->assertSee($account->account_name)
        ->assertDontSeeHtml('data-test="create-account-button"')
        ->assertDontSeeHtml('wire:click="confirmClose('.$account->id.')"');

    $page()->call('openCreateModal')->assertForbidden();
    $page()->call('openEditModal', $account->id)->assertForbidden();
    $page()->call('confirmClose', $account->id)->assertForbidden();

    expect($account->fresh()->is_active)->toBeTrue();
});

test('only people who may change accounts see or search full account numbers', function () {
    Account::factory()->create(['account_name' => 'Operations USD', 'account_number' => '030/02/353/2531267/1/3']);
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role)->givePermissionTo('accounts.view'))->test('pages::admin.accounts');

    $page('Team Member')
        ->assertSee('Operations USD')
        ->assertSeeHtml('•••• /1/3')
        ->assertDontSee('2531267')
        ->set('search', '2531267')
        ->assertDontSee('Operations USD');

    $page('Financial')
        ->assertSee('030/02/353/2531267/1/3')
        ->set('search', '2531267')
        ->assertSee('Operations USD');
});

test('a short account number is hidden completely', function () {
    expect(Account::factory()->make(['account_number' => '999'])->maskedAccountNumber())->toBe('••••')
        ->and(Account::factory()->make(['account_number' => 'CASH-USD-01'])->maskedAccountNumber())->toBe('•••• D-01');
});

test('Financial closes an account', function () {
    $account = Account::factory()->create(['is_active' => true]);

    Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::admin.accounts')
        ->call('confirmClose', $account->id)
        ->call('closeAccount')
        ->assertOk();

    expect($account->fresh()->is_active)->toBeFalse();
});

test('the browser cannot swap the record a page or form is working on', function () {
    expect(fn () => Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::donors.index')
        ->set('editingDonorId', Donor::factory()->create()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the browser cannot point a preview, panel or baseline at another record', function (string $component, Closure $parameters, string $property) {
    expect(fn () => Livewire::actingAs(userWithRole('Financial'))
        ->test($component, $parameters())
        ->set($property, 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'proposal file preview' => ['pages::proposals.show', fn (): array => ['proposal' => Proposal::factory()->create()], 'previewingFileId'],
    'indicator inspect panel' => ['pages::indicators.index', fn (): array => [], 'inspectingIndicatorId'],
    'expanded target' => ['pages::targets.index', fn (): array => [], 'expandedTargetId'],
]);
