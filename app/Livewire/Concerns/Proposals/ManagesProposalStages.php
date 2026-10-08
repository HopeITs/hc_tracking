<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;

/**
 * The register's stage modal: moving a proposal to a new status and
 * recording why, in one transaction with its audit note.
 *
 * Used by proposals/index only. The page provides nothing.
 */
trait ManagesProposalStages
{
    public bool $showStageModal = false;

    #[Locked]
    public ?int $stageProposalId = null;

    public string $newStatus = '';

    public string $statusContext = '';

    public string $statusDate = '';

    /**
     * Open the stage move for one proposal, preset to its next lifecycle stage.
     */
    public function openStageModal(int $proposalId): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->stageProposalId = $proposal->id;
        $this->newStatus = ($proposal->status->next() ?? $proposal->status)->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStageModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction, exactly as on the proposal page:
     * the live status and its audit trail must never disagree.
     */
    public function recordStageChange(): void
    {
        if ($this->stageProposalId === null) {
            return;
        }

        Gate::authorize('update', Proposal::query()->findOrFail($this->stageProposalId));

        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('proposals.choose_the_new_status'),
            'statusContext.required' => __('proposals.record_why_the_status_changed'),
            'statusDate.required' => __('proposals.set_the_date_this_change_took_effect'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->stageProposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->closeStageModal();

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }

    public function closeStageModal(): void
    {
        $this->resetValidation();
        $this->showStageModal = false;
        $this->stageProposalId = null;
        $this->statusContext = '';
    }
}
