<?php

use App\Actions\Proposals\TransitionProposal;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo('2026-10-13 10:00:00'));

/**
 * An active, donor-funded proposal whose final-report milestone is reached
 * and whose final report is filed: everything D-02c needs but the acceptance.
 */
function proposalAwaitingFinalReportAcceptance(User $uploader): Proposal
{
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'donor_id' => Donor::factory()->create(['is_internal' => false])->id,
    ]);
    Milestone::factory()->reached()->create(['proposal_id' => $proposal->id, 'is_final_report' => true]);
    Media::query()->findOrFail(insertMedia('proposal', $proposal->id, $uploader->id))->update(['collection' => MediaCollection::FinalReport]);

    return $proposal;
}

test('a proposal editor records the acceptance from the page and then sees it instead of the button', function () {
    $actor = userWithRole('Financial');
    $proposal = proposalAwaitingFinalReportAcceptance($actor);

    $component = Livewire::actingAs($actor)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSeeHtml('data-test="open-final-report-modal"')
        ->assertDontSeeHtml('data-test="final-report-accepted"')
        ->call('openAcceptanceModal')
        ->assertSet('showAcceptanceModal', true)
        ->assertSet('finalReportAcceptedOn', '2026-10-13')
        ->set('finalReportAcceptedOn', '2026-10-10')
        ->call('recordFinalReportAcceptance')
        ->assertHasNoErrors()
        ->assertSet('showAcceptanceModal', false);

    $proposal->refresh();
    expect($proposal->final_report_accepted_on?->toDateString())->toBe('2026-10-10')
        ->and($proposal->final_report_accepted_by)->toBe($actor->id)
        ->and($proposal->status)->toBe(ProposalStatus::Active);

    $component
        ->assertDontSeeHtml('data-test="open-final-report-modal"')
        ->assertSeeHtml('data-test="final-report-accepted"')
        ->assertSee(__('proposals.final_report_accepted_by', [
            'date' => CarbonImmutable::parse('2026-10-10')->translatedFormat(__('formats.date')),
            'name' => $actor->name,
        ]));
});

test('a user without proposals.update neither sees the button nor may record the acceptance', function () {
    $proposal = proposalAwaitingFinalReportAcceptance(userWithRole('Financial'));
    $viewer = userWithRole('Team Member');

    Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertDontSeeHtml('data-test="open-final-report-modal"');

    Livewire::actingAs($viewer)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('finalReportAcceptedOn', '2026-10-10')
        ->call('recordFinalReportAcceptance')
        ->assertForbidden();

    expect($proposal->fresh()->final_report_accepted_on)->toBeNull();
});

test('the button stays hidden until the final report is filed', function () {
    $actor = userWithRole('Financial');
    $proposal = proposalAwaitingFinalReportAcceptance($actor);
    Media::query()->where('mediable_type', 'proposal')->where('mediable_id', $proposal->id)->delete();

    Livewire::actingAs($actor)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertDontSeeHtml('data-test="open-final-report-modal"');
});

test('a future acceptance date is refused on the date field', function () {
    $actor = userWithRole('Financial');
    $proposal = proposalAwaitingFinalReportAcceptance($actor);

    Livewire::actingAs($actor)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openAcceptanceModal')
        ->set('finalReportAcceptedOn', '2026-10-14')
        ->call('recordFinalReportAcceptance')
        ->assertHasErrors(['finalReportAcceptedOn'])
        ->assertSet('showAcceptanceModal', true);

    expect($proposal->fresh()->final_report_accepted_on)->toBeNull();
});

test('once recorded from the page the proposal can close', function () {
    $actor = userWithRole('Financial');
    $proposal = proposalAwaitingFinalReportAcceptance($actor);

    Livewire::actingAs($actor)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openAcceptanceModal')
        ->call('recordFinalReportAcceptance')
        ->assertHasNoErrors();

    $this->actingAs($actor);
    app(TransitionProposal::class)->handle($proposal->fresh(), ProposalStatus::Completed, ProposalStatus::Active, ['effective_date' => '2026-10-13']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed);
});
