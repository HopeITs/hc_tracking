<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\AssignmentStatus;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Support\Locations\ProjectLocationMirror;
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
        $assignment = ProposalLocation::query()->findOrFail($locationId)->currentOwnerAssignment();
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

        $row = ProposalLocation::query()->findOrFail($this->unblockingProjectId);
        $assignment = $row->currentOwnerAssignment();
        abort_if($assignment === null, 404);
        Gate::authorize('unblock', $assignment);

        $validated = $this->validate(['unblockNote' => ['nullable', 'string', 'max:1000']]);

        // The owner column still lives on the project until Y-18, so the
        // block is lifted where it was raised and the mirror carries it to
        // every row of that project. A row with no project behind it (there
        // is none yet, but the column is nullable) answers for itself.
        $project = $row->project_id === null ? null : Project::query()->find($row->project_id);
        $onProject = $project?->currentAssignment('user_id');

        if ($project !== null && $onProject !== null && $onProject->status === AssignmentStatus::Rejected) {
            $onProject->unblock(Auth::user(), $validated['unblockNote']);
            app(ProjectLocationMirror::class)->syncAcknowledgement($project);
        } else {
            $assignment->unblock(Auth::user(), $validated['unblockNote']);
        }

        $this->closeUnblockModal();

        Flux::toast(variant: 'success', text: __('projects.unblock_done'));
    }
}
