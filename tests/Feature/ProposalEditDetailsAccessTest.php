<?php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Livewire\Livewire;

test('every role but Team Member sees Edit details, even on an approved proposal', function (string $role, bool $sees) {
    $proposal = Proposal::factory()->create();
    $proposal->forceFill(['status' => ProposalStatus::Approved])->save();

    $this->actingAs(userWithRole($role));

    $page = Livewire::test('pages::proposals.show', ['proposal' => $proposal])->set('cardMore', true);

    $sees
        ? $page->assertSee(__('proposals.edit_details'))
        : $page->assertDontSee(__('proposals.edit_details'));
})->with([
    ['General Manager', true],
    ['Managers', true],
    ['MEAL', true],
    ['Financial', true],
    ['Public Relations Manager', true],
    ['Public Relations', true],
    ['Media Team', true],
    ['Team Member', false],
]);

test('a role with only edit-details opens the form but still cannot move the status', function () {
    $proposal = Proposal::factory()->create();
    $user = userWithRole('MEAL');

    $this->actingAs($user);

    Livewire::test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openEditModal', $proposal->id)
        ->assertSet('showProposalModal', true);

    expect($user->can('transition', [$proposal, ProposalStatus::Submitted]))->toBeFalse();
});

test('Team Member cannot open Edit details', function () {
    $proposal = Proposal::factory()->create();

    $this->actingAs(userWithRole('Team Member'));

    Livewire::test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openEditModal', $proposal->id)
        ->assertForbidden();
});
