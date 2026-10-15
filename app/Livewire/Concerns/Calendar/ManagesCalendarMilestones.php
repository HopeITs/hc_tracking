<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\SaveMilestone;
use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\Milestones\PicksMilestoneActivity;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Result;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The milestone dialog on calendar/index: create, edit and delete a
 * milestone, with the parent picker limited to the milestone's own project
 * and never to itself or its descendants.
 *
 * Used by calendar/index only. defaultResponsibleEmployeeId() comes from
 * ScopesCalendarPeople; the #[Computed] calendar() it refreshes from
 * BuildsCalendarGrid.
 *
 * @property-read array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: \Illuminate\Support\Collection<string, Collection<int, Milestone>>, tasksByDay: \Illuminate\Support\Collection<string, Collection<int, Task>>, reportsByDay: \Illuminate\Support\Collection<string, Collection<int, Report>>, proposalsByDay: \Illuminate\Support\Collection<string, Collection<int, Proposal>>, transfersByDay: \Illuminate\Support\Collection<string, Collection<int, TransfersTracking>>, donorFollowUpsByDay: \Illuminate\Support\Collection<string, Collection<int, Donor>>, donorReportsByDay: \Illuminate\Support\Collection<string, Collection<int, DonorReport>>, projectStartsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, projectEndsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, reviewMeetingsByDay: \Illuminate\Support\Collection<string, Collection<int, ProposalStatusNote>>, unscheduledTasks: Collection<int, Task>, nowOffsetPercent: float} $calendar  #[Computed] calendar()
 */
trait ManagesCalendarMilestones
{
    use PicksMilestoneActivity;

    public bool $showMilestoneModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingMilestoneId = null;

    #[Locked]
    public ?int $deletingMilestoneId = null;

    public string $name = '';

    public string $plannedStartDate = '';

    public string $plannedEndDate = '';

    public string $milestoneStatus = 'planned';

    public string $sequence = '1';

    public string $weightPercentage = '0';

    public string $responsibleUserId = '';

    public string $milestoneProjectId = '';

    public string $parentMilestoneId = '';

    public string $milestoneActivityId = '';

    /**
     * Activities the milestone may deliver: for an edit, those of its own
     * project and proposal plus its current link; for a new one, the chosen
     * project's.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function milestoneActivityOptions(): Collection
    {
        if ($this->editingMilestoneId !== null) {
            $editing = Milestone::query()->select(['id', 'project_id', 'proposal_id', 'result_id'])->find($this->editingMilestoneId);

            return $editing === null
                ? new Collection
                : $this->activityOptionsFor($editing->project_id, $editing->proposal_id, $editing->result_id);
        }

        return $this->activityOptionsFor($this->milestoneProjectId === '' ? null : (int) $this->milestoneProjectId);
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function parentOptions(): Collection
    {
        if ($this->milestoneProjectId === '') {
            return new Collection;
        }

        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->parentProjectId())
            ->whereNull('proposal_id')
            ->when($invalidParentIds !== [], fn (Builder $query) => $query->whereNotIn('id', $invalidParentIds))
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function updatedMilestoneProjectId(): void
    {
        if ($this->editingMilestoneId === null) {
            $this->parentMilestoneId = '';
            $this->milestoneActivityId = '';
        }

        unset($this->parentOptions, $this->milestoneActivityOptions);
    }

    public function openCreateModal(?string $date = null): void
    {
        Gate::authorize('create', Milestone::class);

        $this->resetMilestoneForm();

        if ($date !== null) {
            $this->plannedStartDate = $date;
        }

        $this->responsibleUserId = (string) ($this->defaultResponsibleEmployeeId() ?? '');
        $this->showMilestoneModal = true;
    }

    public function openEditModal(int $milestoneId): void
    {
        $milestone = Milestone::query()
            ->select(['id', 'project_id', 'proposal_id', 'result_id', 'name', 'planned_start_date', 'planned_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->findOrFail($milestoneId);

        // Someone who may not edit the milestone is taken to its own page, and
        // so is a milestone on a proposal's plan, which is edited there (T31).
        if ($milestone->proposal_id !== null || Gate::denies('update', $milestone)) {
            $this->redirectRoute('milestones.show', ['milestone' => $milestone], navigate: true);

            return;
        }

        $this->resetValidation();
        $this->editingMilestoneId = $milestone->id;
        $this->name = $milestone->name;
        $this->plannedStartDate = $milestone->planned_start_date->toDateString();
        $this->plannedEndDate = $milestone->planned_end_date?->toDateString() ?? '';
        $this->milestoneStatus = $milestone->status->value;
        $this->sequence = (string) $milestone->sequence;
        $this->weightPercentage = $milestone->weight_percentage;
        $this->responsibleUserId = (string) $milestone->responsible_user_id;
        $this->milestoneProjectId = (string) $milestone->project_id;
        $this->parentMilestoneId = (string) ($milestone->parent_milestone_id ?? '');
        $this->milestoneActivityId = (string) ($milestone->result_id ?? '');
        unset($this->milestoneActivityOptions);
        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $this->name = trim($this->name);

        $existingMilestone = $this->editingMilestoneId === null
            ? null
            : Milestone::query()->findOrFail($this->editingMilestoneId);
        $projectId = $existingMilestone?->project_id ?? (int) $this->milestoneProjectId;

        if ($existingMilestone !== null) {
            Gate::authorize('update', $existingMilestone);
        } else {
            Gate::authorize('create', [Milestone::class, $projectId]);
        }
        $invalidParentIds = $this->invalidParentIds();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'plannedStartDate' => ['required', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'sequence' => ['required', 'integer', 'min:0'],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'milestoneProjectId' => [
                'required',
                'integer',
                Rule::exists(Project::class, 'id'),
                Rule::when($existingMilestone !== null, Rule::in([(string) $existingMilestone?->project_id])),
            ],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $projectId)->whereNull('proposal_id'),
                ),
                Rule::notIn($invalidParentIds),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'plannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'milestoneProjectId.required' => __('milestones.select_the_project'),
            'milestoneProjectId.in' => __('milestones.a_milestone_cannot_be_moved_to_another_project'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $attributes = [
            'name' => $validated['name'],
            'planned_start_date' => $validated['plannedStartDate'],
            'planned_end_date' => $validated['plannedEndDate'] === '' ? null : $validated['plannedEndDate'],
            'status' => $validated['milestoneStatus'],
            'sequence' => (int) $validated['sequence'],
            'weight_percentage' => $validated['weightPercentage'],
            'responsible_user_id' => (int) $validated['responsibleUserId'],
            'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                ? null
                : (int) $validated['parentMilestoneId'],
        ];

        if ($existingMilestone === null) {
            $attributes['project_id'] = (int) $validated['milestoneProjectId'];
        }

        // A refused link rolls the milestone's own changes back with it.
        $milestone = DB::transaction(function () use ($existingMilestone, $attributes): Milestone {
            $saved = app(SaveMilestone::class)->handle($existingMilestone, $attributes);
            $this->linkActivity($saved, $this->milestoneActivityId, 'milestoneActivityId');

            return $saved;
        });
        $wasRecentlyCreated = $milestone->wasRecentlyCreated;

        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
        unset($this->calendar);

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('milestones.milestone_created') : __('milestones.milestone_updated'),
        );
    }

    public function confirmDelete(int $milestoneId): void
    {
        Gate::authorize('delete', Milestone::query()->findOrFail($milestoneId));

        $this->deletingMilestoneId = $milestoneId;
        $this->showDeleteModal = true;
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        $deleted = app(DeleteMilestone::class)->handle($this->deletingMilestoneId);

        $this->deletingMilestoneId = null;
        $this->showDeleteModal = false;
        unset($this->calendar);

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
        $this->editingMilestoneId = null;
        $this->name = '';
        $this->plannedStartDate = '';
        $this->plannedEndDate = '';
        $this->milestoneStatus = 'planned';
        $this->sequence = '1';
        $this->weightPercentage = '0';
        $this->responsibleUserId = '';
        $this->milestoneProjectId = '';
        $this->parentMilestoneId = '';
        $this->milestoneActivityId = '';
        unset($this->parentOptions, $this->milestoneActivityOptions);
    }

    /** @return array<int, int> */
    protected function invalidParentIds(): array
    {
        if ($this->editingMilestoneId === null) {
            return [];
        }

        $milestone = Milestone::query()
            ->select(['id', 'parent_milestone_id'])
            ->with('childrenRecursive:id,parent_milestone_id,sequence')
            ->find($this->editingMilestoneId);

        if ($milestone === null) {
            return [];
        }

        return [...$milestone->descendants()->modelKeys(), $milestone->id];
    }

    protected function parentProjectId(): int
    {
        if ($this->editingMilestoneId !== null) {
            return (int) Milestone::query()->whereKey($this->editingMilestoneId)->value('project_id');
        }

        return (int) $this->milestoneProjectId;
    }
}
