<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\DeleteProposal;
use App\Models\Proposal;
use App\Support\TargetCounts;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The register's delete confirmation for a proposal. The delete itself, its
 * guards and the framework clean-up live in DeleteProposal.
 *
 * Used by proposals/index only. The page uses WithPagination (resetPage).
 */
trait DeletesProposals
{
    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingProposalId = null;

    /**
     * Whether this proposal may be deleted.
     *
     * donor_proposal.proposal_id RESTRICTs on delete, deliberately: without it
     * one proposal delete would reach through the agreement into
     * transfers_tracking and erase every record of money received. The UI
     * refuses first so the user gets a reason instead of a query exception.
     */
    protected function canDelete(int $proposalId): bool
    {
        $proposal = Proposal::query()
            ->select(['id'])
            ->withCount('donorProposals')
            ->find($proposalId);

        return $proposal !== null && $proposal->donor_proposals_count === 0;
    }

    /**
     * The proposal the open confirmation falls on: its name for the record card
     * and its internal number, which must be typed before the delete unlocks.
     */
    #[Computed]
    public function deletingProposal(): ?Proposal
    {
        if ($this->deletingProposalId === null) {
            return null;
        }

        return Proposal::query()->select(['id', 'name', 'slug', 'internal_project_number'])->find($this->deletingProposalId);
    }

    /**
     * The targets the open confirmation would delete with the proposal, and
     * how many have reports, which keep the proposal from being deleted
     * (Y-10, DeleteProposal).
     *
     * @return array{count: int, reported: int}
     */
    #[Computed]
    public function deletingProposalTargets(): array
    {
        $proposal = $this->deletingProposal;

        return $proposal === null ? ['count' => 0, 'reported' => 0] : TargetCounts::ofProposal($proposal);
    }

    public function confirmDelete(int $proposalId): void
    {
        Gate::authorize('delete', Proposal::query()->findOrFail($proposalId));

        $this->deletingProposalId = $proposalId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingProposalId = null;
        $this->showDeleteModal = false;
        unset($this->deletingProposal, $this->deletingProposalTargets);
    }

    public function deleteProposal(): void
    {
        if ($this->deletingProposalId === null) {
            return;
        }

        $proposal = Proposal::query()->find($this->deletingProposalId);

        try {
            if ($proposal !== null) {
                app(DeleteProposal::class)->handle($proposal);
            }
        } catch (ValidationException $exception) {
            $this->cancelDelete();

            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());

            return;
        }

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('proposals.proposal_deleted'));
    }
}
