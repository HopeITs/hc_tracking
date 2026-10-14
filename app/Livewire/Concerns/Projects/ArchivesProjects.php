<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\RestoreProject;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Archiving and restoring projects from the directory: the confirmation
 * dialog's state, the archive and restore actions.
 *
 * Used by projects/index only. The page provides WithPagination's resetPage().
 */
trait ArchivesProjects
{
    #[Locked]
    public ?int $archivingProjectId = null;

    /**
     * Carried separately from archivingProjectId because a flux:modal only opens
     * from a boolean bound with wire:model; :open never reopens on a round trip.
     */
    public bool $showArchiveModal = false;

    public function confirmArchive(int $projectId): void
    {
        Gate::authorize('delete', Project::query()->findOrFail($projectId));

        $this->archivingProjectId = $projectId;
        $this->showArchiveModal = true;
    }

    public function cancelArchive(): void
    {
        $this->archivingProjectId = null;
        $this->showArchiveModal = false;
    }

    /**
     * The project under the archive confirmation, with the work it carries, so
     * the dialog can say what leaves the active board. Nothing is destroyed.
     *
     * @return array{project: Project, carries: array<string, int>}|null
     */
    #[Computed]
    public function archivingProject(): ?array
    {
        if ($this->archivingProjectId === null) {
            return null;
        }

        $project = Project::query()
            ->select(['id', 'name', 'status'])
            ->withCount(['milestones', 'risks', 'proposals'])
            ->find($this->archivingProjectId);

        if ($project === null) {
            return null;
        }

        return [
            'project' => $project,
            'carries' => array_filter([
                __('common.milestones') => $project->milestones_count,
                __('common.risks') => $project->risks_count,
                __('common.proposals') => $project->proposals_count,
            ]),
        ];
    }

    /**
     * Retire the project into the Archived lifecycle state.
     *
     * Nothing in this application is soft-deleted, so archiving is how a
     * project leaves the active portfolio without destroying its delivery
     * record. restoreProject() puts it back.
     */
    public function archiveProject(): void
    {
        if ($this->archivingProjectId === null) {
            return;
        }

        $project = Project::query()->find($this->archivingProjectId);

        if ($project === null) {
            $this->cancelArchive();

            return;
        }

        app(ArchiveProject::class)->handle($project);

        $this->archivingProjectId = null;
        $this->showArchiveModal = false;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('projects.project_archived'));
    }

    /**
     * Take an archived project out of the archive (D-27): closed when it has
     * dates, else a draft, and only while no other open project holds its
     * center and program. RestoreProject enforces both.
     */
    public function restoreProject(int $projectId): void
    {
        $project = Project::query()->find($projectId);

        if ($project === null) {
            return;
        }

        try {
            $status = app(RestoreProject::class)->handle($project);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: $exception->validator->errors()->first());

            return;
        }

        $this->resetPage();

        Flux::toast(variant: 'success', text: __('projects.project_restored_as', ['status' => $status->label()]));
    }
}
