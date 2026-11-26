<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\RestoreProject;
use App\Models\Project;
use App\Models\ProposalLocation;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Archiving and restoring places from the directory: the confirmation
 * dialog's state, the archive and restore actions.
 *
 * Used by projects/index and projects/show. The page provides WithPagination's resetPage().
 *
 * Y-16 (D-93): the dialog is opened on a **location row** and authorised on
 * it. The two actions still write the project, which the mirror copies back
 * to the row until Y-17 moves the writers; the row's project is reached
 * through `project_id` (never the row's own id — they are different keys).
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

    public function confirmArchive(int $locationId): void
    {
        Gate::authorize('delete', ProposalLocation::query()->findOrFail($locationId));

        $this->archivingProjectId = $locationId;
        $this->showArchiveModal = true;
    }

    public function cancelArchive(): void
    {
        $this->archivingProjectId = null;
        $this->showArchiveModal = false;
    }

    /**
     * The row under the archive confirmation, with the work it carries, so
     * the dialog can say what leaves the active board. Nothing is destroyed.
     *
     * @return array{project: ProposalLocation, carries: array<string, int>}|null
     */
    #[Computed]
    public function archivingProject(): ?array
    {
        if ($this->archivingProjectId === null) {
            return null;
        }

        $row = ProposalLocation::query()
            ->select(['id', 'proposal_id', 'project_id', 'city_id', 'program_id', 'city_wide', 'status'])
            ->with(['city:id,name_en,name_ar', 'program:id', 'centers', 'proposal:id,name'])
            ->withCount(['risks'])
            ->find($this->archivingProjectId);

        if ($row === null) {
            return null;
        }

        return [
            'project' => $row,
            'carries' => array_filter([
                __('common.risks') => $row->risks_count,
                // A row is of exactly one proposal (D-93).
                __('common.proposals') => 1,
            ]),
        ];
    }

    /**
     * Retire the place into the Archived lifecycle state.
     *
     * Nothing in this application is soft-deleted, so archiving is how a
     * place leaves the active portfolio without destroying its delivery
     * record. restoreProject() puts it back.
     */
    public function archiveProject(): void
    {
        if ($this->archivingProjectId === null) {
            return;
        }

        $project = $this->projectOfRow($this->archivingProjectId);

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
    public function restoreProject(int $locationId): void
    {
        $project = $this->projectOfRow($locationId);

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

    /**
     * The project a row stands for, which the two actions still write
     * (Y-17 moves them to the row).
     */
    private function projectOfRow(int $locationId): ?Project
    {
        $projectId = ProposalLocation::query()->whereKey($locationId)->value('project_id');

        return $projectId === null ? null : Project::query()->find((int) $projectId);
    }
}
