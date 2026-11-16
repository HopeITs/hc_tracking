<?php

use App\Models\Proposal;
use Livewire\Livewire;

/*
| The MEAL Manager slot holder opens every proposal tab and works it: editing,
| status, reviewers and the Funding tab's agreements and transfers (user
| decision 2026-11-16). Recording the donor's approval (D-70) and deleting
| stay with their roles; MEAL officers gain nothing.
*/

dataset('meal manager grants', [
    'proposals.view', 'proposals.update', 'proposals.manage-reviewers', 'proposals.correct-status',
    'donors.view',
    'funding-agreements.view', 'funding-agreements.create', 'funding-agreements.update',
    'transfers.view', 'transfers.create', 'transfers.update',
]);

test('the MEAL manager holds every proposal page ability', function (string $ability) {
    expect(mealManager()->can($ability))->toBeTrue();
})->with('meal manager grants');

test('a MEAL officer gains none of them', function (string $ability) {
    holdMealManagerSlot();

    expect(userWithRole('MEAL')->can($ability))->toBeFalse();
})->with('meal manager grants');

test('the MEAL manager still cannot record the donor approval or delete', function () {
    $mealManager = mealManager();
    $proposal = Proposal::factory()->create();

    expect($mealManager->can('approve', $proposal))->toBeFalse()
        ->and($mealManager->can('delete', $proposal))->toBeFalse()
        ->and($mealManager->can('funding-agreements.delete'))->toBeFalse()
        ->and($mealManager->can('transfers.delete'))->toBeFalse();
});

test('the MEAL manager opens the proposal with the Funding tab', function () {
    $mealManager = mealManager();
    $proposal = Proposal::factory()->create();

    $this->actingAs($mealManager)->get(route('proposals.index'))->assertOk();
    $this->get(route('proposals.show', $proposal))->assertOk();

    $tabs = collect(Livewire::actingAs($mealManager)->test('pages::proposals.show', ['proposal' => $proposal])->instance()->tabs())->pluck('key');

    expect($tabs)->toContain('funding', 'projects', 'logframe', 'targets', 'plan', 'reports', 'documents');
});

test('a MEAL officer is still kept out of a proposal they do not review', function () {
    holdMealManagerSlot();

    $this->actingAs(userWithRole('MEAL'))
        ->get(route('proposals.show', Proposal::factory()->create()))
        ->assertForbidden();
});
