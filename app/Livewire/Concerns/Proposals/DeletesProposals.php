<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * The register's delete confirmation for a proposal.
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
    }

    public function deleteProposal(): void
    {
        if ($this->deletingProposalId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingProposalId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('proposals.this_proposal_has_a_funding_agreement_remove_the_agreement_f'),
            );

            return;
        }

        $proposal = Proposal::query()->findOrFail($this->deletingProposalId);

        Gate::authorize('delete', $proposal);

        $proposal->delete();

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('proposals.proposal_deleted'));
    }
}
