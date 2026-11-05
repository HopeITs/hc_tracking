<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\ExtendProposal;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use App\Models\Media;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The "Extend" dialog of proposals/show (W-08, D-60).
 *
 * The status the page saw travels as the expected status, so a proposal that
 * moved meanwhile is refused. `?extend=1` opens the dialog once for a viewer
 * who may extend (the W-33 alert's "Request extension" action). Used by a page
 * that has WithFileUploads, provides forgetReadings() and the #[Computed]
 * proposal().
 */
trait ManagesProposalExtension
{
    public bool $showExtensionModal = false;

    public string $extensionDateEnd = '';

    public string $extensionReason = '';

    public $extensionFile = null;

    /**
     * Deep link: opens the dialog once, then clears itself.
     */
    #[Url(as: 'extend', except: false)]
    public bool $extendLink = false;

    public function openExtensionFromLink(): void
    {
        if ($this->extendLink && $this->canExtend()) {
            $this->openExtensionModal();
        }

        $this->extendLink = false;
    }

    public function openExtensionModal(): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->extensionDateEnd = '';
        $this->extensionReason = '';
        $this->extensionFile = null;
        $this->showExtensionModal = true;
    }

    public function closeExtensionModal(): void
    {
        $this->showExtensionModal = false;
        $this->extensionDateEnd = '';
        $this->extensionReason = '';
        $this->extensionFile = null;
        $this->resetValidation();
    }

    public function saveExtension(): void
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();

        try {
            app(ExtendProposal::class)->handle($proposal, $proposal->status, [
                'new_date_end' => $this->extensionDateEnd,
                'reason' => $this->extensionReason,
                'file' => $this->extensionFile,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key === 'status' ? 'extensionReason' : $key, $messages[0]);
            }

            return;
        }

        $this->closeExtensionModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.extend_done'));
    }

    /**
     * Whether the viewer may extend this proposal right now: it is active and
     * they may update it. Reads the status only.
     */
    public function canExtend(): bool
    {
        $proposal = Proposal::query()->select(['id', 'status'])->find($this->proposalId);

        return $proposal !== null
            && $proposal->status === ProposalStatus::Active
            && Gate::allows('update', $proposal);
    }

    /**
     * The amendment file of the latest extension, for the date cell.
     */
    #[Computed]
    public function latestAmendment(): ?Media
    {
        if ($this->proposal->extended_date_end === null) {
            return null;
        }

        $noteId = ProposalStatusNote::query()
            ->where('proposal_id', $this->proposalId)
            ->where('kind', StatusNoteKind::Extension->value)
            ->latest('id')
            ->value('id');

        return $noteId === null ? null : Media::query()
            ->where('mediable_type', 'proposal_status_note')
            ->where('mediable_id', $noteId)
            ->where('collection', MediaCollection::Amendment->value)
            ->first();
    }
}
