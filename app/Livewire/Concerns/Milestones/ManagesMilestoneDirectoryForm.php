<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Milestones;

use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Enums\MilestoneStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Result;
use App\Models\Scopes\TaskVisibilityScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The create-milestone dialog and the delete confirmation on
 * milestones/index, plus the quick status change the board uses.
 *
 * Used by milestones/index only. The page provides the #[Computed]
 * projectsWithMilestones this trait refreshes after a save, status change or
 * delete.
 *
 * @property-read Collection<int, Project> $projectsWithMilestones
 */
trait ManagesMilestoneDirectoryForm
{
    use PicksMilestoneActivity;

    public bool $showMilestoneModal = false;

    #[Locked]
    public ?int $deletingMilestoneId = null;

    public bool $showDeleteModal = false;

    public string $name = '';

    public string $description = '';

    public string $plannedStartDate = '';

    public string $plannedEndDate = '';

    public string $milestoneStatus = 'planned';

    public string $weightPercentage = '0';

    public string $responsibleUserId = '';

    public string $milestoneProjectId = '';

    public string $parentMilestoneId = '';

    public string $milestoneActivityId = '';

    public function updatedMilestoneProjectId(): void
    {
        $this->parentMilestoneId = '';
        $this->milestoneActivityId = '';

        unset($this->parentOptions, $this->milestoneActivityOptions);
    }

    /**
     * Activities a milestone of the chosen project may deliver.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function milestoneActivityOptions(): Collection
    {
        return $this->activityOptionsFor($this->milestoneProjectId === '' ? null : (int) $this->milestoneProjectId);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function parentOptions(): Collection
    {
        if ($this->milestoneProjectId === '') {
            return new Collection;
        }

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', (int) $this->milestoneProjectId)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function deletingMilestone(): ?Milestone
    {
        if ($this->deletingMilestoneId === null) {
            return null;
        }

        return Milestone::query()->select(['id', 'name'])->find($this->deletingMilestoneId);
    }

    protected function canDelete(int $milestoneId): bool
    {
        $milestone = Milestone::query()
            ->select(['id'])
            ->withCount(['children', 'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)])
            ->find($milestoneId);

        return $milestone !== null
            && $milestone->children_count === 0
            && $milestone->tasks_count === 0;
    }

    public function openCreateModal(?string $date = null, ?string $status = null, ?int $projectId = null, ?int $parentId = null): void
    {
        Gate::authorize('create', [Milestone::class, $projectId]);

        $this->resetMilestoneForm();

        if ($date !== null) {
            $this->plannedStartDate = $date;
        }

        if ($status !== null && MilestoneStatus::tryFrom($status) !== null) {
            $this->milestoneStatus = $status;
        }

        if ($parentId !== null) {
            $parent = Milestone::query()->select(['id', 'project_id'])->find($parentId);

            if ($parent !== null) {
                $this->milestoneProjectId = (string) $parent->project_id;
                $this->parentMilestoneId = (string) $parent->id;
            }
        } elseif ($projectId !== null && Project::query()->whereKey($projectId)->exists()) {
            $this->milestoneProjectId = (string) $projectId;
        }

        unset($this->parentOptions, $this->milestoneActivityOptions);

        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedStartDate' => ['required', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'milestoneProjectId' => ['required', 'integer', Rule::exists(Project::class, 'id')],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', (int) $this->milestoneProjectId),
                ),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'plannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'milestoneProjectId.required' => __('milestones.select_the_project'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        Gate::authorize('create', [Milestone::class, (int) $validated['milestoneProjectId']]);

        DB::transaction(function () use ($validated): void {
            $created = app(SaveMilestone::class)->handle(null, [
                'project_id' => (int) $validated['milestoneProjectId'],
                'name' => $validated['name'],
                'description' => $validated['description'] === '' ? null : $validated['description'],
                'planned_start_date' => $validated['plannedStartDate'],
                'planned_end_date' => $validated['plannedEndDate'] === '' ? null : $validated['plannedEndDate'],
                'status' => $validated['milestoneStatus'],
                'weight_percentage' => $validated['weightPercentage'],
                'responsible_user_id' => (int) $validated['responsibleUserId'],
                'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                    ? null
                    : (int) $validated['parentMilestoneId'],
            ]);

            // A refused link rolls the new milestone back with it.
            $this->linkActivity($created, $this->milestoneActivityId, 'milestoneActivityId');
        });

        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
        unset($this->projectsWithMilestones);

        Flux::toast(variant: 'success', text: __('milestones.milestone_created'));
    }

    public function quickSetStatus(int $milestoneId, string $status): void
    {
        $newStatus = MilestoneStatus::tryFrom($status);
        $milestone = Milestone::query()->find($milestoneId);

        if ($newStatus === null || $milestone === null) {
            return;
        }

        Gate::authorize('updateProgress', $milestone);

        $milestone->status = $newStatus;
        $milestone->save();
        unset($this->projectsWithMilestones);

        Flux::toast(variant: 'success', text: __('milestones.milestone_updated'));
    }

    public function confirmDelete(int $milestoneId): void
    {
        Gate::authorize('delete', Milestone::query()->findOrFail($milestoneId));

        $this->deletingMilestoneId = $milestoneId;
        $this->showDeleteModal = true;
        unset($this->deletingMilestone);
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingMilestoneId = null;
        unset($this->deletingMilestone);
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        $milestoneId = $this->deletingMilestoneId;
        $deleted = app(DeleteMilestone::class)->handle($milestoneId);

        $this->showDeleteModal = false;
        $this->deletingMilestoneId = null;
        unset($this->deletingMilestone);
        unset($this->projectsWithMilestones);

        if (! $deleted) {
            Flux::toast(
                variant: 'danger',
                text: __('milestones.this_milestone_still_has_nested_milestones_or_tasks_move_or'),
            );

            return;
        }

        Flux::toast(variant: 'success', text: __('milestones.milestone_deleted'));
    }

    public function closeMilestoneModal(): void
    {
        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
    }

    protected function resetMilestoneForm(): void
    {
        $this->resetValidation();
        $this->name = '';
        $this->description = '';
        $this->plannedStartDate = '';
        $this->plannedEndDate = '';
        $this->milestoneStatus = 'planned';
        $this->weightPercentage = '0';
        $this->responsibleUserId = '';
        $this->milestoneProjectId = '';
        $this->parentMilestoneId = '';
        $this->milestoneActivityId = '';
        unset($this->parentOptions, $this->milestoneActivityOptions);
    }
}
