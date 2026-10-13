<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Enums\ProjectStatus;
use App\Models\HrCenter;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
     * The «Program — Center» name last proposed, so a name the person typed
     * themselves is never replaced.
     */
    #[Locked]
    public string $proposedName = '';

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
        $this->startDate = $project->start_date?->toDateString() ?? '';
        $this->endDate = $project->end_date?->toDateString() ?? '';
        $this->targetBeneficiaries = self::formInteger($project->target_beneficiaries);
        $this->targetMen = self::formInteger($project->target_men);
        $this->targetWomen = self::formInteger($project->target_women);
        $this->targetBoys = self::formInteger($project->target_boys);
        $this->targetGirls = self::formInteger($project->target_girls);
        $this->centerId = $project->center_id === null ? '' : (string) $project->center_id;
        $this->savedCenterId = $project->center_id;
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
            'centerId' => [
                Rule::when($this->editingProjectId === null || $this->savedCenterId !== null, ['required'], ['nullable']),
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $center = HrCenter::remembered((int) $value);

                    if ($center === null || (! $center->is_active && $center->id !== $this->savedCenterId)) {
                        $fail(__('projects.choose_the_center'));
                    }
                },
            ],
            'programId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'userId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id'), Rule::when($mustPickProgramManager, [Rule::in($this->ownerOptions->pluck('id')->all())])],
        ], [
            'name.required' => __('projects.enter_a_project_name'),
            'centerId.required' => __('projects.choose_the_center'),
            'centerId.integer' => __('projects.choose_the_center'),
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

        $center = $validated['centerId'] === null || $validated['centerId'] === '' ? null : HrCenter::remembered((int) $validated['centerId']);

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
            'center_id' => $center?->id,
            'city_id' => $center === null ? $project->city_id : $center->city_id,
            'program_id' => $mayReassign ? (int) $validated['programId'] : $project->program_id,
            'user_id' => $mayReassign ? (int) $validated['userId'] : $project->user_id,
        ]);

        $openProject = DB::transaction(function () use ($project): ?Project {
            $claimsPair = $project->center_id !== null
                && $project->status->isOpen()
                && (! $project->exists || $project->isDirty(['center_id', 'program_id']));

            $openProject = $claimsPair
                ? Project::openProjectFor($project->center_id, $project->program_id, $project->exists ? $project->id : null)
                : null;

            if ($openProject === null) {
                $project->save();
            }

            return $openProject;
        });

        if ($openProject !== null) {
            $this->addError('centerId', __('projects.open_project_exists', ['name' => $openProject->name]));

            return;
        }

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
        $this->centerId = '';
        $this->savedCenterId = null;
        $this->proposedName = '';
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
