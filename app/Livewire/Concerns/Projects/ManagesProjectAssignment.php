<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Models\Assignment;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The owner assignment on projects/show: the assignment that stands for the
 * project, and the dialog where the owner acknowledges or rejects it.
 *
 * Used by projects/show only. The page provides projectId; mount() opens the
 * dialog when the page is reached with ?respond=reject.
 *
 * @property int $projectId
 * @property-read Assignment|null $ownerAssignment  #[Computed] ownerAssignment()
 */
trait ManagesProjectAssignment
{
    public bool $showAssignmentModal = false;

    #[Locked]
    public string $assignmentMode = 'reject';

    public string $assignmentNote = '';

    /**
     * The owner assignment that stands for this project right now.
     */
    #[Computed]
    public function ownerAssignment(): ?Assignment
    {
        return Project::query()->findOrFail($this->projectId)->currentAssignment('user_id')?->load('assigner');
    }

    public function openAssignmentModal(string $mode): void
    {
        abort_unless(in_array($mode, ['acknowledge', 'reject'], true), 404);

        $assignment = $this->ownerAssignment;
        abort_if($assignment === null, 404);
        Gate::authorize('respond', $assignment);

        $this->resetValidation();
        $this->assignmentMode = $mode;
        $this->assignmentNote = '';
        $this->showAssignmentModal = true;
    }

    public function closeAssignmentModal(): void
    {
        $this->showAssignmentModal = false;
        $this->assignmentNote = '';
        $this->resetValidation();
    }

    /**
     * Acknowledge (note optional) or reject (reason required).
     * Authorized again here: the dialog may have been open for a while.
     */
    public function submitAssignment(): void
    {
        $assignment = $this->ownerAssignment;
        abort_if($assignment === null, 404);

        $user = Auth::user();
        Gate::authorize('respond', $assignment);

        $this->assignmentNote = trim($this->assignmentNote);

        $validated = $this->validate([
            'assignmentNote' => $this->assignmentMode === 'reject'
                ? ['required', 'string', 'min:10', 'max:1000']
                : ['nullable', 'string', 'max:1000'],
        ], [
            'assignmentNote.required' => __('projects.assignment_reason_required'),
            'assignmentNote.min' => __('projects.assignment_reason_required'),
        ]);

        match ($this->assignmentMode) {
            'acknowledge' => $assignment->acknowledge($user, $validated['assignmentNote']),
            default => $assignment->reject($user, $validated['assignmentNote']),
        };

        $mode = $this->assignmentMode;
        $this->closeAssignmentModal();
        unset($this->ownerAssignment);

        Flux::toast(variant: 'success', text: match ($mode) {
            'acknowledge' => __('projects.assignment_acknowledge_done'),
            default => __('projects.assignment_reject_done'),
        });
    }

    /**
     * The inbox changed (a poll, a push, or a read / acknowledge / reject done
     * from the bell): re-render so the banner and the unread dot follow it.
     */
    #[On('notification-received')]
    public function refreshFromInbox(): void
    {
        unset($this->ownerAssignment);
    }
}
