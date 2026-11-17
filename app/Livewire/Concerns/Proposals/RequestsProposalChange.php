<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\RequestProposalChange;
use App\Actions\Proposals\SaveProposal;
use App\Models\Proposal;
use App\Support\ProposalChangeLines;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * "Request a change" on an approved proposal (Y-09 slice 2, D-89, D-89a).
 *
 * Opens the proposal form (ManagesProposalForm) in change mode: a required
 * reason first, then a summary step that lists what the request changes,
 * then RequestProposalChange. The summary runs SaveProposal::changesFor()
 * inside a transaction that is always rolled back, so it is the very list
 * the action stores, and nothing is written before Send.
 *
 * Used by proposals/show beside ManagesProposalForm, whose proposalSaved()
 * and proposalFormData() it reuses.
 */
trait RequestsProposalChange
{
    /** The form is a change request, not an edit. */
    #[Locked]
    public bool $changeRequestMode = false;

    public string $changeReason = '';

    /**
     * The summary step's lines, null while the form is shown.
     *
     * @var list<array{field: string, label: string, place: string|null, from: string, to: string}>|null
     */
    #[Locked]
    public ?array $changeSummary = null;

    /** The proposal's `updated_at` when the form opened (stale guard). */
    #[Locked]
    public ?string $changeExpectedUpdatedAt = null;

    public function openChangeRequest(): void
    {
        $proposal = $this->changeRequestProposal();

        Gate::authorize('requestChange', $proposal);

        if ($proposal->changeRequests()->pending()->exists()) {
            Flux::toast(variant: 'warning', text: __('proposals.change_request_pending'));

            return;
        }

        $this->openEditModal($proposal->id);
        $this->changeRequestMode = true;
        $this->changeReason = '';
        $this->changeSummary = null;
        $this->changeExpectedUpdatedAt = $proposal->updated_at?->toDateTimeString();
    }

    /**
     * Check the form and show what the request would change. Nothing is
     * written: the differences are computed on the locked proposal and the
     * transaction is rolled back.
     */
    public function previewChangeRequest(): void
    {
        $this->resetValidation();

        $proposal = $this->changeRequestProposal();

        Gate::authorize('requestChange', $proposal);

        if (mb_strlen(trim($this->changeReason)) < 10) {
            $this->addError('changeReason', __('proposals.change_request_reason_min'));

            return;
        }

        $actor = $this->proposalActor();

        DB::beginTransaction();

        try {
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $changes = app(SaveProposal::class)->changesFor($locked, $this->proposalFormData(false), $actor);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } finally {
            DB::rollBack();
        }

        if ($changes === []) {
            $this->addError('changeRequest', __('proposals.change_request_empty'));

            return;
        }

        $this->changeSummary = ProposalChangeLines::for($changes, $actor);
    }

    /**
     * Back from the summary to the form, everything typed kept.
     */
    public function backToChangeForm(): void
    {
        $this->changeSummary = null;
        $this->resetValidation();
    }

    public function sendChangeRequest(): void
    {
        $proposal = $this->changeRequestProposal();

        try {
            app(RequestProposalChange::class)->handle($proposal, $this->proposalFormData(false), $this->changeReason, $this->changeExpectedUpdatedAt);
        } catch (ValidationException $exception) {
            $this->changeSummary = null;
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->closeChangeRequest();
        $this->proposalSaved($proposal, false);

        Flux::toast(variant: 'success', text: __('proposals.change.sent'));
    }

    /**
     * Close the form and leave change mode.
     */
    public function closeChangeRequest(): void
    {
        $this->closeProposalModal();
        $this->changeRequestMode = false;
        $this->changeReason = '';
        $this->changeSummary = null;
        $this->changeExpectedUpdatedAt = null;
    }

    private function changeRequestProposal(): Proposal
    {
        return Proposal::query()->findOrFail($this->proposalFormScope() ?? $this->editingProposalId);
    }
}
