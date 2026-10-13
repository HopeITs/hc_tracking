<?php

use App\Actions\Proposals\RecordFinalReportAcceptance;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * An active proposal whose final-report milestone is reached.
 */
function activeProposalWithFinalMilestone(bool $selfFunded = false): Proposal
{
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'donor_id' => Donor::factory()->create(['is_internal' => $selfFunded])->id,
    ]);
    Milestone::factory()->reached()->create(['proposal_id' => $proposal->id, 'is_final_report' => true]);

    return $proposal;
}

function fileOnProposal(Proposal $proposal, MediaCollection $collection, User $uploader): void
{
    Media::query()->findOrFail(insertMedia('proposal', $proposal->id, $uploader->id))->update(['collection' => $collection]);
}

function closeProposal(User $actor, Proposal $proposal): mixed
{
    test()->actingAs($actor);

    return app(TransitionProposal::class)->handle($proposal, ProposalStatus::Completed, ProposalStatus::Active, ['effective_date' => '2026-10-13']);
}

beforeEach(fn () => $this->travelTo('2026-10-13 10:00:00'));

test('closing needs the final report file', function () {
    $actor = userWithRole('Financial');
    $proposal = activeProposalWithFinalMilestone();

    expect(fn () => closeProposal($actor, $proposal))->toThrow(ValidationException::class, __('proposals.final_report_required'));
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Active);
});

test('closing needs the donor acceptance when the donor is external', function () {
    $actor = userWithRole('Financial');
    $proposal = activeProposalWithFinalMilestone();
    fileOnProposal($proposal, MediaCollection::FinalReport, $actor);

    expect(fn () => closeProposal($actor, $proposal))->toThrow(ValidationException::class, __('proposals.final_report_required'));
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Active);
});

test('closing needs the final-report milestone reached', function () {
    $actor = userWithRole('Financial');
    $proposal = activeProposalWithFinalMilestone(selfFunded: true);
    Milestone::query()->where('proposal_id', $proposal->id)->update(['status' => 'ongoing']);
    fileOnProposal($proposal, MediaCollection::FinalReport, $actor);

    expect(fn () => closeProposal($actor, $proposal))->toThrow(ValidationException::class, __('proposals.final_report_required'));
});

test('file and recorded acceptance close the proposal without the acceptance email', function () {
    $actor = userWithRole('Financial');
    $proposal = activeProposalWithFinalMilestone();
    fileOnProposal($proposal, MediaCollection::FinalReport, $actor);
    $this->actingAs($actor);
    app(RecordFinalReportAcceptance::class)->handle($proposal, CarbonImmutable::parse('2026-10-10'));

    closeProposal($actor, $proposal);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed);
});

test('a self-funded proposal closes with the milestone and the file only', function () {
    $actor = userWithRole('Financial');
    $proposal = activeProposalWithFinalMilestone(selfFunded: true);
    fileOnProposal($proposal, MediaCollection::FinalReport, $actor);

    closeProposal($actor, $proposal);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed);
});

test('recording the acceptance stores the date and the acceptor and logs it', function () {
    $actor = userWithRole('Public Relations Manager');
    $proposal = activeProposalWithFinalMilestone();
    fileOnProposal($proposal, MediaCollection::FinalReport, $actor);
    $this->actingAs($actor);

    app(RecordFinalReportAcceptance::class)->handle($proposal, CarbonImmutable::parse('2026-10-13'));

    $proposal->refresh();
    expect($proposal->final_report_accepted_on?->toDateString())->toBe('2026-10-13')
        ->and($proposal->final_report_accepted_by)->toBe($actor->id);
    $this->assertDatabaseHas('activities', ['subject_id' => $proposal->id, 'event' => 'proposal.final_report_accepted']);
    $this->assertDatabaseMissing('activities', ['subject_id' => $proposal->id, 'event' => 'proposal.updated']);
});

test('the acceptance cannot be recorded before the final report is filed or in the future', function (bool $withFile, string $acceptedOn, string $message) {
    $actor = userWithRole('Financial');
    $proposal = activeProposalWithFinalMilestone();
    if ($withFile) {
        fileOnProposal($proposal, MediaCollection::FinalReport, $actor);
    }
    $this->actingAs($actor);

    expect(fn () => app(RecordFinalReportAcceptance::class)->handle($proposal, CarbonImmutable::parse($acceptedOn)))
        ->toThrow(ValidationException::class, __($message));
    expect($proposal->fresh()->final_report_accepted_on)->toBeNull();
})->with([
    'no file' => [false, '2026-10-12', 'proposals.final_report_required'],
    'future date' => [true, '2026-10-14', 'proposals.date_not_future'],
]);

test('a user without proposals.update cannot record the acceptance', function () {
    $proposal = activeProposalWithFinalMilestone();
    fileOnProposal($proposal, MediaCollection::FinalReport, userWithRole('Financial'));
    $this->actingAs(userWithRole('Managers'));

    expect(fn () => app(RecordFinalReportAcceptance::class)->handle($proposal, CarbonImmutable::parse('2026-10-12')))
        ->toThrow(AuthorizationException::class);
    expect($proposal->fresh()->final_report_accepted_on)->toBeNull();
});
