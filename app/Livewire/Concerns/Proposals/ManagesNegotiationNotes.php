<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\AddNegotiationNote;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The "Add negotiation note" modal of proposals/show (W-07, D-59).
 *
 * The status the page saw travels as the expected status, so a proposal that
 * moved meanwhile is refused. Used by a page that has WithFileUploads and
 * provides forgetReadings().
 */
trait ManagesNegotiationNotes
{
    public bool $showNegotiationModal = false;

    public string $negotiationText = '';

    public $negotiationFile = null;

    public function openNegotiationModal(): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->negotiationText = '';
        $this->negotiationFile = null;
        $this->showNegotiationModal = true;
    }

    public function closeNegotiationModal(): void
    {
        $this->showNegotiationModal = false;
        $this->negotiationText = '';
        $this->negotiationFile = null;
        $this->resetValidation();
    }

    public function saveNegotiationNote(): void
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();

        try {
            app(AddNegotiationNote::class)->handle($proposal, $proposal->status, [
                'text' => $this->negotiationText,
                'file' => $this->negotiationFile,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key === 'status' ? 'negotiationText' : $key, $messages[0]);
            }

            return;
        }

        $this->closeNegotiationModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.negotiation_note_added'));
    }
}
