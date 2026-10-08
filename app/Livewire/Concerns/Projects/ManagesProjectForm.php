<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\ProjectStatus;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The create/edit project dialog: its form properties, the city and owner
 * pickers, opening, saving and closing it.
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

    public string $cityId = '';

    public string $programId = '';

    public string $userId = '';

    /** @return Collection<int, HrCity> */
    #[Computed]
    public function cityOptions(): Collection
    {
        return HrCity::query()->select(['id', 'name_en', 'name_ar'])->orderBy(HrCity::nameColumn())->orderBy('id')->get();
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
                'program_id',
                'user_id',
            ])
            ->findOrFail($projectId);

        Gate::authorize('update', $project);

        $this->resetValidation();
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->startDate = $project->start_date?->toDateString() ?? '';
        $this->endDate = $project->end_date?->toDateString() ?? '';
        $this->targetBeneficiaries = self::formInteger($project->target_beneficiaries);
        $this->targetMen = self::formInteger($project->target_men);
        $this->targetWomen = self::formInteger($project->target_women);
        $this->targetBoys = self::formInteger($project->target_boys);
        $this->targetGirls = self::formInteger($project->target_girls);
        $this->cityId = (string) $project->city_id;
        $this->programId = (string) $project->program_id;
        $this->userId = (string) $project->user_id;
        $this->showProjectModal = true;
    }

    public function saveProject(): void
    {
        if ($this->editingProjectId === null) {
            Gate::authorize('create', Project::class);
        } else {
            Gate::authorize('update', Project::query()->findOrFail($this->editingProjectId));
        }

        $this->name = trim($this->name);
        $this->description = trim($this->description);

        // Only someone who may change the owner has to pick a program manager;
        // for anyone else the submitted owner is ignored below.
        $mustPickProgramManager = $this->editingProjectId === null
            || Gate::allows('reassign', Project::query()->findOrFail($this->editingProjectId));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'startDate' => ['nullable', 'date'],
            'endDate' => [
                'nullable',
                'date',
                Rule::when($this->startDate !== '', ['after_or_equal:startDate']),
            ],
            'targetBeneficiaries' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetMen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetWomen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetBoys' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetGirls' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'cityId' => ['required', 'integer', Rule::exists(HrCity::class, 'id')],
            'programId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'userId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id'), Rule::when($mustPickProgramManager, [Rule::in($this->ownerOptions->pluck('id')->all())])],
        ], [
            'name.required' => __('projects.enter_a_project_name'),
            'cityId.required' => __('projects.select_a_city_2'),
            'cityId.exists' => __('projects.the_selected_city_is_no_longer_available'),
            'programId.required' => __('projects.select_a_program_2'),
            'programId.exists' => __('projects.the_selected_program_is_no_longer_available'),
            'userId.required' => __('projects.select_a_project_owner'),
            'userId.exists' => __('projects.the_selected_owner_is_no_longer_available'),
            'userId.in' => __('projects.owner_must_be_program_manager'),
            'endDate.after_or_equal' => __('projects.the_end_date_must_be_on_or_after_the_start_date'),
        ]);

        $project = $this->editingProjectId === null
            ? new Project
            : Project::query()->findOrFail($this->editingProjectId);

        // An owner edits their project but does not move it to another
        // program or owner, and archiving goes through archiveProject().
        $mayReassign = ! $project->exists || Gate::allows('reassign', $project);

        // A new project always starts as a draft; an existing one keeps its
        // status, which changes only from the project page.
        if (! $project->exists) {
            $project->status = ProjectStatus::Draft;
        }

        $project->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'start_date' => self::nullableString($validated['startDate']),
            'end_date' => self::nullableString($validated['endDate']),
            'target_beneficiaries' => self::nullableInteger($validated['targetBeneficiaries']),
            'target_men' => self::nullableInteger($validated['targetMen']),
            'target_women' => self::nullableInteger($validated['targetWomen']),
            'target_boys' => self::nullableInteger($validated['targetBoys']),
            'target_girls' => self::nullableInteger($validated['targetGirls']),
            'city_id' => (int) $validated['cityId'],
            'program_id' => $mayReassign ? (int) $validated['programId'] : $project->program_id,
            'user_id' => $mayReassign ? (int) $validated['userId'] : $project->user_id,
        ])->save();

        $wasRecentlyCreated = $project->wasRecentlyCreated;

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
        $this->cityId = '';
        $this->programId = '';
        $this->userId = '';
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
