<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Unblocking a project its owner rejected, from the directory: the dialog's
 * state and the unblock action the top level opens from a Blocked badge.
 *
 * Used by projects/index only.
 */
trait UnblocksProjects
{
    /** The unblock dialog the top level opens from a Blocked badge. */
    #[Locked]
    public ?int $unblockingProjectId = null;

    public bool $showUnblockModal = false;

    public string $unblockNote = '';

    public function openUnblockModal(int $projectId): void
    {
        $assignment = Project::query()->findOrFail($projectId)->ownerAssignment;
        abort_if($assignment === null, 404);
        Gate::authorize('unblock', $assignment);

        $this->resetValidation();
        $this->unblockingProjectId = $projectId;
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

        $assignment = Project::query()->findOrFail($this->unblockingProjectId)->ownerAssignment;
        abort_if($assignment === null, 404);
        Gate::authorize('unblock', $assignment);

        $validated = $this->validate(['unblockNote' => ['nullable', 'string', 'max:1000']]);

        $assignment->unblock(Auth::user(), $validated['unblockNote']);

        $this->closeUnblockModal();

        Flux::toast(variant: 'success', text: __('projects.unblock_done'));
    }
}
