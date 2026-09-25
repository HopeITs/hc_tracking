<?php

use App\Enums\ProposalStatus;
use App\Models\Account;
use App\Models\Donor;
use App\Models\Proposal;
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

test('only whoever may update a proposal moves its status from the dossier', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $move = fn (string $role) => Livewire::actingAs(userWithRole($role))
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', 'Countersigned.')
        ->set('statusDate', '2026-04-01')
        ->call('changeStatus');

    $move('Team Member')->assertForbidden();
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);

    $move('Public Relations Manager')->assertHasNoErrors();
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved);
});

test('the browser cannot swap the record a page or form is working on', function () {
    $proposal = Proposal::factory()->create();

    expect(fn () => Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::proposals.dossier', ['proposal' => $proposal])
        ->set('proposalId', Proposal::factory()->create()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::donors.index')
        ->set('editingDonorId', Donor::factory()->create()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
