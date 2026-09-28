<?php

use App\Models\Account;
use App\Models\Donor;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('a Team Member sees accounts but cannot open, edit or close one', function () {
    $account = Account::factory()->create(['is_active' => true]);
    $member = userWithRole('Team Member');
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
