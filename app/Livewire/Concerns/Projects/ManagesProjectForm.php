<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Projects\RestoreProject;
use App\Actions\Projects\SaveProject;
use App\Enums\ProjectStatus;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Support\SimilarProjects;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
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
 * Used by projects/index and projects/show. The page provides the program and user pickers
 * (`programOptions()`, `userOptions()`), which `ownerOptions()` reads, and
 * WithPagination's resetPage().
 *
 * @property-read Collection<int, HrEmployee> $ownerOptions  #[Computed] ownerOptions() below
 * @property-read Collection<int, Program> $programOptions  #[Computed] programOptions() on the page
 * @property-read Collection<int, HrEmployee> $userOptions  #[Computed] userOptions() on the page
 * @property-read list<array{id: int, name: string, center: string, program: string, status: string}> $similarProjects  #[Computed] similarProjects() below
 * @property-read array{id: int, name: string, slug: string, state: string, canRestore: bool}|null $existingPairProject  #[Computed] existingPairProject() below
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

    /**
     * The host center and the city of a project with no center of its own
     * (D-55, W-01). Both are read only while no center is chosen; with a
     * center the server takes the center's city and clears the host.
     */
    public string $hostCenterId = '';

    public string $cityId = '';

    public string $programId = '';

    public string $userId = '';

    /**
     * «This is a new project», ticked when similar projects are listed
     * (T10). Cleared whenever the name or the placement changes, since the
     * list it answered no longer applies.
     */
    public bool $confirmNewProject = false;

    /**
     * The chosen sector ids (T34), as strings from the picker's checkboxes.
     *
     * @var list<string>
     */
    public array $sectorIds = [];

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
     * The chosen city, or null while none is chosen.
     */
    private function chosenCityId(): ?int
    {
        return ctype_digit($this->cityId) ? (int) $this->cityId : null;
    }

    /**
     * The physical centers of the chosen city (W-02): active ones, plus the
     * edited project's own center when HR has since deactivated it. Empty
     * until a city is chosen; the dialog then disables the select and says why.
     *
     * @return Collection<int, HrCenter>
     */
    #[Computed]
    public function centerOptions(): Collection
    {
        $cityId = $this->chosenCityId();

        if ($cityId === null) {
            return new Collection;
        }

        $centers = HrCenter::options()->filter(fn (HrCenter $center): bool => $center->city_id === $cityId)->values();
        $savedCenter = $this->savedCenterId === null ? null : HrCenter::remembered($this->savedCenterId);

        if ($savedCenter !== null && ! $savedCenter->is_active && $savedCenter->city_id === $cityId) {
            $centers->push($savedCenter);
        }

        return $centers;
    }

    /**
     * The centers that can follow up a project with no center of its own
     * (D-55): every active center outside the chosen city.
     *
     * @return Collection<int, HrCenter>
     */
    #[Computed]
    public function hostCenterOptions(): Collection
    {
        $cityId = $this->chosenCityId();

        return HrCenter::options()->filter(fn (HrCenter $center): bool => $center->city_id !== $cityId)->values();
    }

    /**
     * Active HR cities for the dialog's city picker, in the interface
     * language. Named apart from the directory's cityOptions() filter.
     *
     * @return Collection<int, HrCity>
     */
    #[Computed]
    public function placementCityOptions(): Collection
    {
        return HrCity::query()->where('is_active', true)->select(['id', 'name_en', 'name_ar'])->orderBy(HrCity::nameColumn())->orderBy('id')->get();
    }

    /**
     * The labels the dialog's browser-side name default reads: program,
     * center and city names by id, so choosing them proposes
     * «Program — Center» (or «Program — City» without a center) with no
     * round trip.
     *
     * @return array{programs: array<int, string>, centers: array<int, string>, cities: array<int, string>}
     */
    #[Computed]
    public function nameDefaultLabels(): array
    {
        return [
            'programs' => $this->programOptions->mapWithKeys(fn (Program $program): array => [$program->id => $program->name])->all(),
            'centers' => HrCenter::options()->mapWithKeys(fn (HrCenter $center): array => [$center->id => $center->name])->all(),
            'cities' => $this->placementCityOptions->mapWithKeys(fn (HrCity $city): array => [$city->id => $city->name])->all(),
        ];
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
        $this->confirmNewProject = false;

        if ($value === '' || ($this->editingProjectId !== null && ! Gate::allows('reassign', Project::query()->findOrFail($this->editingProjectId)))) {
            return;
        }

        $managerId = Program::query()->whereKey((int) $value)->value('manager_id');

        if ($managerId !== null) {
            $this->userId = (string) $managerId;
        }
    }

    /**
     * Choosing a center drops the host center: a project has one or the other.
     * The city stays; the center was offered because it stands in it.
     */
    public function updatedCenterId(string $value): void
    {
        $this->confirmNewProject = false;

        if ($value !== '') {
            $this->hostCenterId = '';
        }
    }

    public function updatedHostCenterId(): void
    {
        $this->confirmNewProject = false;
    }

    /**
     * A new city drops a center that does not stand in it and a host that
     * does (a host is always a center outside the project's own city).
     */
    public function updatedCityId(): void
    {
        $this->confirmNewProject = false;
        $cityId = $this->chosenCityId();

        if (ctype_digit($this->centerId) && HrCenter::remembered((int) $this->centerId)?->city_id !== $cityId) {
            $this->centerId = '';
        }

        if (ctype_digit($this->hostCenterId) && HrCenter::remembered((int) $this->hostCenterId)?->city_id === $cityId) {
            $this->hostCenterId = '';
        }
    }

    public function updatedName(): void
    {
        $this->confirmNewProject = false;
    }

    /**
     * Existing projects with a similar name in other centers (T10), as plain
     * rows: name, center, program and status. Information only.
     *
     * @return list<array{id: int, name: string, center: string, program: string, status: string}>
     */
    #[Computed]
    public function similarProjects(): array
    {
        if (! $this->showProjectModal || $this->editingProjectId !== null || mb_strlen(trim($this->name)) < 3) {
            return [];
        }

        $matches = SimilarProjects::find(
            $this->name,
            ctype_digit($this->programId) ? (int) $this->programId : null,
            ctype_digit($this->centerId) ? (int) $this->centerId : null,
        );

        return array_values($matches->map(fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'center' => $project->center_id === null && $project->host_center_id === null ? __('proposals.no_center') : $project->locationLabel(),
            'program' => $this->hrProgramName($project->program_id),
            'status' => $project->status->label(),
        ])->all());
    }

    /**
     * The project already holding the chosen center and program (T10): the
     * open one that makes a second refused, or the newest closed or archived
     * one that a new proposal can take back. Null until both are chosen.
     *
     * @return array{id: int, name: string, slug: string, state: string, canRestore: bool}|null
     */
    #[Computed]
    public function existingPairProject(): ?array
    {
        if (! $this->showProjectModal || $this->editingProjectId !== null || ! ctype_digit($this->programId)) {
            return null;
        }

        $placement = $this->chosenPlacement();

        if ($placement === null) {
            return null;
        }

        ['centerId' => $centerId, 'cityId' => $cityId] = $placement;
        $programId = (int) $this->programId;
        $open = Project::openProjectFor($centerId, $cityId, $programId);
        $found = $open ?? Project::query()
            ->select(['id', 'slug', 'name', 'status', 'city_id', 'center_id', 'host_center_id', 'program_id'])
            ->when(
                $centerId !== null,
                fn (Builder $query) => $query->where('center_id', $centerId),
                fn (Builder $query) => $query->whereNull('center_id')->where('city_id', $cityId),
            )
            ->where('program_id', $programId)
            ->orderByDesc('id')
            ->first();

        if ($found === null) {
            return null;
        }

        return [
            'id' => $found->id,
            'name' => $found->name,
            'slug' => $found->slug,
            'state' => $found->status === ProjectStatus::Archived ? 'archived' : ($open === null ? 'closed' : 'open'),
            'canRestore' => $found->status === ProjectStatus::Archived && Gate::allows('delete', $found),
        ];
    }

    /**
     * Take the archived project of the chosen center and program out of the
     * archive, so a proposal can be added to it (D-27). The status is written
     * by RestoreProject.
     */
    public function restoreExistingProject(): void
    {
        $existing = $this->existingPairProject;

        abort_if($existing === null || $existing['state'] !== 'archived', 404);

        $project = Project::query()->whereKey($existing['id'])->firstOrFail();

        $status = app(RestoreProject::class)->handle($project);

        unset($this->existingPairProject);

        Flux::toast(variant: 'success', text: __('projects.project_restored_as', ['status' => $status->label()]));
    }

    /**
     * The placement the form has chosen so far, as the pair the open-project
     * rule keys on: the center (with its city), or, for a hosted project,
     * null with the chosen city. Null until enough is chosen.
     *
     * @return array{centerId: int|null, cityId: int}|null
     */
    private function chosenPlacement(): ?array
    {
        if (ctype_digit($this->centerId)) {
            $center = HrCenter::remembered((int) $this->centerId);

            return $center === null ? null : ['centerId' => $center->id, 'cityId' => $center->city_id];
        }

        if (ctype_digit($this->hostCenterId) && ctype_digit($this->cityId)) {
            return ['centerId' => null, 'cityId' => (int) $this->cityId];
        }

        return null;
    }

    private function hrProgramName(int $programId): string
    {
        try {
            $name = Program::nameOf($programId);
        } catch (QueryException) {
            $name = '';
        }

        return $name !== '' ? $name : '#'.$programId;
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
                'host_center_id',
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
        $this->hostCenterId = $project->host_center_id === null ? '' : (string) $project->host_center_id;
        $this->cityId = (string) $project->city_id;
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

        if ($project === null && $this->similarProjects !== [] && ! $this->confirmNewProject) {
            $this->addError('confirmNewProject', __('projects.similar_confirm'));

            return;
        }

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
            'hostCenterId' => $this->hostCenterId,
            'cityId' => $this->cityId,
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
        $this->hostCenterId = '';
        $this->cityId = '';
        $this->savedCenterId = null;
        $this->savedStatus = null;
        $this->confirmNewProject = false;
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
