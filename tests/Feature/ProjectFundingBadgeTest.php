<?php

use App\Enums\AssignmentStatus;
use App\Enums\ProposalStatus;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Livewire\Livewire;

/**
 * A draft **place** of a proposal at the given status (Y-17: the badge reads
 * the row, whose own `fundingState()` is what every page calls;
 * `Project::fundingState()` reads an appointment nothing raises any more and
 * retires with the table in Y-18).
 */
function draftLinkedTo(ProposalStatus $status, bool $ownerAcknowledged = false): ProposalLocation
{
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['status' => $status]);
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 10]);
    // Y-16 (D-93): the badge reads the place, and a place is of exactly one
    // proposal — the one whose status this fixture is about. Y-17: the
    // appointment is the row's, so it is acknowledged there.
    $row = rowFor($project, $proposal);

    // rowFor() builds the row without recording, so no appointment is raised
    // by its hooks: the fixture states the answer it wants outright.
    Assignment::query()->create([
        'assignable_type' => 'proposal_location',
        'assignable_id' => $row->id,
        'field' => 'responsible_employee_id',
        'employee_id' => $row->responsible_employee_id ?? insertHrEmployee(),
        'status' => $ownerAcknowledged ? AssignmentStatus::Acknowledged : AssignmentStatus::Pending,
        'responded_at' => $ownerAcknowledged ? now() : null,
    ]);

    return $row->fresh();
}

test('fundingState follows the proposal and the owner', function () {
    expect(draftLinkedTo(ProposalStatus::Approved)->fundingState())->toBe('awaiting_owner')
        ->and(draftLinkedTo(ProposalStatus::Submitted)->fundingState())->toBe('awaiting_funding')
        ->and(draftLinkedTo(ProposalStatus::Approved, ownerAcknowledged: true)->fundingState())->toBeNull()
        ->and(draftLinkedTo(ProposalStatus::ProposalDraft)->fundingState())->toBeNull()
        ->and(rowFor(Project::factory()->approved()->create())->fundingState())->toBe('funded_awaiting_template')
        ->and(rowFor(Project::factory()->active()->create())->fundingState())->toBeNull();
});

test('a project that was rejected by its owner is not shown as awaiting them', function () {
    $row = draftLinkedTo(ProposalStatus::Approved);
    $row->currentAssignment('responsible_employee_id')->forceFill(['status' => AssignmentStatus::Rejected])->save();

    expect($row->fresh()->fundingState())->toBeNull();
});

test('the detail page and the directory show the funding badge in English and Arabic', function (string $locale) {
    app()->setLocale($locale);
    $waiting = draftLinkedTo(ProposalStatus::Approved);
    $user = userWithRole('General Manager');

    Livewire::actingAs($user)->test('pages::projects.show', ['project' => $waiting->slug])
        ->assertSeeHtml('data-test="funding-badge-awaiting_owner"')
        ->assertSee(__('projects.awaiting_owner_acknowledgement'));

    $awaitingFunding = draftLinkedTo(ProposalStatus::Submitted);
    $funded = Project::factory()->approved()->create();
    rowFor($funded);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSee(__('projects.awaiting_owner_acknowledgement'))
        ->assertSee(__('projects.awaiting_funding'))
        ->assertSee(__('projects.funded_awaiting_template'));

    expect([$awaitingFunding->id, $funded->id])->each->toBeInt();
})->with(['en', 'ar']);
