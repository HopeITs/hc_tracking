<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Projects\SaveProject;
use App\Enums\ProjectStatus;
use App\Models\HrCenter;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The create/edit project dialog: its form properties, the center and owner
 * pickers, opening, saving and closing it.
 *
 * A project runs from an HR center (D-30): the center is required for a new
 * project, the city is always the center's and is written here, and only one
 * open project may hold a (center, program) pair, checked under a lock by
 * Project::openProjectFor(). A project not yet mapped to a center keeps its
 * city and can still be edited until one is chosen.
 *
 * Used by projects/index only. The page provides the program and user pickers
 * (`programOptions()`, `userOptions()`), which `ownerOptions()` reads, and
 * WithPagination's resetPage().
 *
 * @property-read Collection<int, HrEmployee> $ownerOptions  #[Computed] ownerOptions() below
 * @property-read Collection<int, Program> $programOptions  #[Computed] programOptions() on the page
 * @property-read Collection<int, HrEmployee> $userOptions  #[Computed] userOptions() on the page
 */
trait ManagesProjectForm
{
    public bool $showProjectModal = false;

    #[Locked]
    public ?int $editingProjectId = null;

    public string $name = '';

    public string $description = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $targetBeneficiaries = '';

    public string $targetMen = '';

    public string $targetWomen = '';

    public string $targetBoys = '';

    public string $targetGirls = '';

    public string $centerId = '';

    public string $programId = '';

    public string $userId = '';

    /**
     * The chosen sector ids (T34), as strings from the picker's checkboxes.
     *
     * @var list<string>
     */
    public array $sectorIds = [];

    /**
     * The «Program — Center» name last proposed, so a name the person typed
     * themselves is never replaced.
     */
    #[Locked]
    public string $proposedName = '';

    /**
     * The status of the edited project, null on create. It only decides
     * whether the date inputs show; the form never writes a status.
     */
    #[Locked]
    public ?string $savedStatus = null;

    /**
     * The center the edited project already has: it stays choosable after HR
     * deactivates it, and makes the center required on edit.
     */
    #[Locked]
    public ?int $savedCenterId = null;

    /**
     * Active centers, plus the edited project's own center when it is inactive.
     *
     * @return Collection<int, HrCenter>
     */
    #[Computed]
    public function centerOptions(): Collection
    {
        $centers = HrCenter::options();
        $savedCenter = $this->savedCenterId === null ? null : HrCenter::remembered($this->savedCenterId);

        if ($savedCenter !== null && ! $savedCenter->is_active) {
            $centers->push($savedCenter);
        }

        return $centers;
    }

    /**
     * Who can own a project: the managers of programs (loaded from HR into
     * programs.manager_id), plus, when editing, the project's present owner so
     * the form still shows them.
     *
     * @return Collection<int, HrEmployee>
     */
    #[Computed]
    public function ownerOptions(): Collection
    {
        $ids = $this->programOptions->pluck('manager_id');

        if ($this->editingProjectId !== null) {
            $ids->push(Project::query()->whereKey($this->editingProjectId)->value('user_id'));
        }

        return $this->userOptions->whereIn('id', $ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all())->values();
    }

    /**
     * Choosing a program proposes its manager as the owner; the person filling
     * the form can still pick another program manager. Left alone when the
     * program has no manager or the editor may not change the owner.
     */
    public function updatedProgramId(string $value): void
    {
        if ($value === '' || ($this->editingProjectId !== null && ! Gate::allows('reassign', Project::query()->findOrFail($this->editingProjectId)))) {
            return;
        }

        $managerId = Program::query()->whereKey((int) $value)->value('manager_id');

        if ($managerId !== null) {
            $this->userId = (string) $managerId;
        }

        $this->proposeName();
    }

    public function updatedCenterId(): void
    {
        $this->proposeName();
    }

    /**
     * Name a new project «Program — Center» once both are chosen, unless the
     * person already typed a name of their own.
     */
    protected function proposeName(): void
    {
        if ($this->editingProjectId !== null || ! ctype_digit($this->programId) || ! ctype_digit($this->centerId)) {
            return;
        }

        $center = HrCenter::remembered((int) $this->centerId);
        $program = Program::query()->find((int) $this->programId, ['id']);

        if ($center === null || $program === null || ($this->name !== '' && $this->name !== $this->proposedName)) {
            return;
        }

        $this->name = $this->proposedName = $program->name.' — '.$center->name;
    }

    /**
     * Whether the form offers the dates: only for a project that is past
     * draft (approved, active, closed or archived); a draft gets its dates
     * from the proposal approval.
     */
    public function datesEditable(): bool
    {
        return $this->savedStatus !== null && $this->savedStatus !== ProjectStatus::Draft->value;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Project::class);

        $this->resetProjectForm();
        $this->showProjectModal = true;
    }

    public function openEditModal(int $projectId): void
    {
        $project = Project::query()
            ->select([
                'id',
                'name',
                'description',
                'status',
                'start_date',
                'end_date',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
                'city_id',
                'center_id',
                'program_id',
                'user_id',
            ])
            ->findOrFail($projectId);

        Gate::authorize('update', $project);

        $this->resetValidation();
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->savedStatus = $project->status->value;
        // A draft has no dates in the form (contracts.md §3): whatever it still holds stays untouched.
        $this->startDate = $this->datesEditable() ? ($project->start_date?->toDateString() ?? '') : '';
        $this->endDate = $this->datesEditable() ? ($project->end_date?->toDateString() ?? '') : '';
        $this->targetBeneficiaries = self::formInteger($project->target_beneficiaries);
        $this->targetMen = self::formInteger($project->target_men);
        $this->targetWomen = self::formInteger($project->target_women);
        $this->targetBoys = self::formInteger($project->target_boys);
        $this->targetGirls = self::formInteger($project->target_girls);
        $this->centerId = $project->center_id === null ? '' : (string) $project->center_id;
        $this->savedCenterId = $project->center_id;
        $this->programId = (string) $project->program_id;
        $this->userId = (string) $project->user_id;
        $this->sectorIds = array_values($project->sectors()->ordered()->pluck('sectors.id')->map(fn (mixed $id): string => (string) $id)->all());
        $this->showProjectModal = true;
    }

    public function saveProject(): void
    {
        $project = $this->editingProjectId === null ? null : Project::query()->findOrFail($this->editingProjectId);

        $project === null
            ? Gate::authorize('create', Project::class)
            : Gate::authorize('update', $project);

        $saved = app(SaveProject::class)->handle($project, [
            'name' => $this->name,
            'description' => $this->description,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'targetBeneficiaries' => $this->targetBeneficiaries,
            'targetMen' => $this->targetMen,
            'targetWomen' => $this->targetWomen,
            'targetBoys' => $this->targetBoys,
            'targetGirls' => $this->targetGirls,
            'centerId' => $this->centerId,
            'programId' => $this->programId,
            'userId' => $this->userId,
            'sectorIds' => $this->sectorIds,
        ], auth()->user());

        $wasRecentlyCreated = $saved->wasRecentlyCreated;

        $this->showProjectModal = false;
        $this->resetProjectForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('projects.project_created') : __('projects.project_updated'),
        );
    }

    /**
     * The inbox changed (poll, push, or an action done from the bell): the
     * unread dots and the Rejected badges are read fresh on this re-render.
     */
    #[On('notification-received')]
    public function refreshFromInbox(): void {}

    public function closeProjectModal(): void
    {
        $this->showProjectModal = false;
        $this->resetProjectForm();
    }

    protected function resetProjectForm(): void
    {
        $this->resetValidation();
        $this->editingProjectId = null;
        $this->name = '';
        $this->description = '';
        $this->startDate = '';
        $this->endDate = '';
        $this->targetBeneficiaries = '';
        $this->targetMen = '';
        $this->targetWomen = '';
        $this->targetBoys = '';
        $this->targetGirls = '';
        $this->centerId = '';
        $this->savedCenterId = null;
        $this->savedStatus = null;
        $this->proposedName = '';
        $this->programId = '';
        $this->userId = '';
        $this->sectorIds = [];
    }

    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected static function formInteger(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
