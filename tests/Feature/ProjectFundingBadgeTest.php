<?php

use App\Enums\AssignmentStatus;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use Livewire\Livewire;

/**
 * A draft project linked to a proposal at the given status.
 */
function draftLinkedTo(ProposalStatus $status, bool $ownerAcknowledged = false): Project
{
    $project = Project::factory()->create();

    if ($ownerAcknowledged) {
        $project->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    }

    Proposal::factory()->create(['status' => $status])->projects()->attach($project->id, ['target_beneficiaries' => 10]);

    return $project;
}

test('fundingState follows the proposal and the owner', function () {
    expect(draftLinkedTo(ProposalStatus::Approved)->fundingState())->toBe('awaiting_owner')
        ->and(draftLinkedTo(ProposalStatus::Submitted)->fundingState())->toBe('awaiting_funding')
        ->and(draftLinkedTo(ProposalStatus::Approved, ownerAcknowledged: true)->fundingState())->toBeNull()
        ->and(draftLinkedTo(ProposalStatus::ProposalDraft)->fundingState())->toBeNull()
        ->and(Project::factory()->approved()->create()->fundingState())->toBe('funded_awaiting_template')
        ->and(Project::factory()->active()->create()->fundingState())->toBeNull();
});

test('a project that was rejected by its owner is not shown as awaiting them', function () {
    $project = draftLinkedTo(ProposalStatus::Approved);
    $project->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Rejected])->save();

    expect($project->fresh()->fundingState())->toBeNull();
});

test('the detail page and the directory show the funding badge in English and Arabic', function (string $locale) {
    app()->setLocale($locale);
    $waiting = draftLinkedTo(ProposalStatus::Approved);
    $user = userWithRole('General Manager');

    Livewire::actingAs($user)->test('pages::projects.show', ['project' => $waiting])
        ->assertSeeHtml('data-test="funding-badge-awaiting_owner"')
        ->assertSee(__('projects.awaiting_owner_acknowledgement'));

    $awaitingFunding = draftLinkedTo(ProposalStatus::Submitted);
    $funded = Project::factory()->approved()->create();

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSee(__('projects.awaiting_owner_acknowledgement'))
        ->assertSee(__('projects.awaiting_funding'))
        ->assertSee(__('projects.funded_awaiting_template'));

    expect([$awaitingFunding->id, $funded->id])->each->toBeInt();
})->with(['en', 'ar']);
