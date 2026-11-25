<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Models\ProposalLocation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Unblocking a place its owner rejected, from the directory: the dialog's
 * state and the unblock action the top level opens from a Blocked badge.
 *
 * Used by projects/index only. Y-16 (D-93): the assignment is read from the
 * **location row** the directory lists.
 */
trait UnblocksProjects
{
    /** The unblock dialog the top level opens from a Blocked badge. */
    #[Locked]
    public ?int $unblockingProjectId = null;

    public bool $showUnblockModal = false;

    public string $unblockNote = '';

    public function openUnblockModal(int $locationId): void
    {
        $assignment = ProposalLocation::query()->findOrFail($locationId)->ownerAssignment;
        abort_if($assignment === null, 404);
        Gate::authorize('unblock', $assignment);

        $this->resetValidation();
        $this->unblockingProjectId = $locationId;
        $this->unblockNote = '';
        $this->showUnblockModal = true;
    }

    public function closeUnblockModal(): void
    {
        $this->showUnblockModal = false;
        $this->unblockingProjectId = null;
        $this->unblockNote = '';
        $this->resetValidation();
    }

    /**
     * Lift the block (note optional). Authorized again here: the dialog may
     * have been open for a while.
     */
    public function unblockProject(): void
    {
        abort_if($this->unblockingProjectId === null, 404);

        $assignment = ProposalLocation::query()->findOrFail($this->unblockingProjectId)->ownerAssignment;
        abort_if($assignment === null, 404);
        Gate::authorize('unblock', $assignment);

        $validated = $this->validate(['unblockNote' => ['nullable', 'string', 'max:1000']]);

        $assignment->unblock(Auth::user(), $validated['unblockNote']);

        $this->closeUnblockModal();

        Flux::toast(variant: 'success', text: __('projects.unblock_done'));
    }
}
