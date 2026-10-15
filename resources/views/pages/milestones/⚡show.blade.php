<?php

use App\Actions\Milestones\SaveMilestone;
use App\Actions\Milestones\SaveProposalMilestone;
use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\Milestones\PicksMilestoneActivity;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.milestone')] class extends Component {
    use AddsQuickTask;
    use PicksMilestoneActivity;

    #[Locked]
    public int $milestoneId;

    public string $name = '';
    public string $description = '';
    public string $plannedStartDate = '';
    public string $plannedEndDate = '';
    public string $milestoneStatus = 'planned';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $parentMilestoneId = '';
    public string $milestoneActivityId = '';

    public bool $showRelatedModal = false;
    public string $relatedName = '';
    public string $relatedDescription = '';
    public string $relatedPlannedStartDate = '';
    public string $relatedPlannedEndDate = '';
    public string $relatedStatus = 'planned';
    public string $relatedWeightPercentage = '0';
    public string $relatedResponsibleUserId = '';
    public string $relatedParentMilestoneId = '';
    public string $relatedActivityId = '';

    public function mount(Milestone $milestone): void
    {
        $this->milestoneId = $milestone->id;

        $freshMilestone = $this->milestone;
        $this->name = $freshMilestone->name;
        $this->description = $freshMilestone->description ?? '';
        $this->plannedStartDate = $freshMilestone->planned_start_date->toDateString();
        $this->plannedEndDate = $freshMilestone->planned_end_date?->toDateString() ?? '';
        $this->milestoneStatus = $freshMilestone->status->value;
        $this->weightPercentage = $freshMilestone->weight_percentage;
        $this->responsibleUserId = (string) $freshMilestone->responsible_user_id;
        $this->parentMilestoneId = (string) ($freshMilestone->parent_milestone_id ?? '');
        $this->milestoneActivityId = (string) ($freshMilestone->result_id ?? '');
    }

    #[Computed]
    public function milestone(): Milestone
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'proposal_id', 'result_id', 'name', 'description', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id', 'updated_at'])
            ->with(['project:id,name', 'proposal:id,name', 'responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'parent:id,name'])
            ->findOrFail($this->milestoneId);
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function relatedMilestones(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'status', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'sequence'])
            ->where('parent_milestone_id', $this->milestoneId)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Task> */
    #[Computed]
    public function relatedTasks(): Collection
    {
        return Task::query()
            ->select(['id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->where('source_type', 'milestone')
            ->where('source_id', $this->milestoneId)
            ->whereNull('parent_task_id')
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function parentOptions(): Collection
    {
        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where(fn ($query) => $this->constrainToParentCandidates($query))
            ->whereNotIn('id', $invalidParentIds)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Activities this milestone may deliver, keeping its current link.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function activityOptions(): Collection
    {
        return $this->activityOptionsFor($this->milestone->project_id, $this->milestone->proposal_id, $this->milestone->result_id);
    }

    /**
     * Activities a new milestone of this project may deliver.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function relatedActivityOptions(): Collection
    {
        return $this->activityOptionsFor($this->milestone->project_id);
    }

    /**
     * Why the stored activity link no longer fits (Milestone::activityLinkIssues()).
     *
     * @return list<string>
     */
    #[Computed]
    public function activityLinkIssues(): array
    {
        return $this->milestone->activityLinkIssues();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function relatedParentOptions(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where(fn ($query) => $this->constrainToParentCandidates($query))
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Save the milestone. Editors (milestones.update, or the owner of its
     * project) change every field; its responsible person only moves its
     * status, so for them every other field keeps its stored value.
     */
    public function updateMilestone(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $milestone = Milestone::query()->findOrFail($this->milestoneId);
        $planProposal = $milestone->proposal_id === null ? null : Proposal::query()->findOrFail($milestone->proposal_id);
        $canEdit = $planProposal === null
            ? Gate::allows('update', $milestone)
            : Gate::inspect('planForProposal', [Milestone::class, $planProposal, $milestone])->allowed();

        if (! $canEdit) {
            Gate::authorize('updateProgress', $milestone);
        }

        if ($canEdit && $planProposal !== null) {
            $this->updatePlanMilestone($milestone);

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedStartDate' => ['required', 'date'],
            'plannedEndDate' => ['nullable', 'date', 'after_or_equal:plannedStartDate'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $this->constrainToParentCandidates($query),
                ),
                Rule::notIn($this->invalidParentIds()),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'plannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        DB::transaction(function () use ($milestone, $canEdit, $validated): void {
            app(SaveMilestone::class)->handle($milestone, $canEdit ? [
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
            ] : [
                'status' => $validated['milestoneStatus'],
            ]);

            // Only an editor moves the link; the responsible person changes status alone.
            if ($canEdit) {
                $this->linkActivity($milestone, $this->milestoneActivityId, 'milestoneActivityId');
            }
        });

        unset($this->milestone, $this->parentOptions, $this->activityOptions, $this->activityLinkIssues);

        Flux::toast(variant: 'success', text: __('milestones.milestone_updated'));
    }

    /**
     * A proposal plan milestone (T31) changes only through the plan window:
     * its plan fields go through SaveProposalMilestone, which locks the
     * proposal and checks the window, the period and the tree; status and
     * weight follow in the same transaction.
     */
    private function updatePlanMilestone(Milestone $milestone): void
    {
        $validated = $this->validate([
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'weightPercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $proposal = Proposal::query()->findOrFail($milestone->proposal_id);

        try {
            DB::transaction(function () use ($proposal, $milestone, $validated): void {
                $saved = app(SaveProposalMilestone::class)->handle($proposal, $milestone->id, [
                    'name' => $this->name,
                    'description' => $this->description,
                    'planned_start_date' => $this->plannedStartDate,
                    'planned_end_date' => $this->plannedEndDate,
                    'responsible_employee_id' => $this->responsibleUserId,
                    'project_id' => $milestone->project_id,
                    'parent_milestone_id' => $this->parentMilestoneId,
                ]);

                app(SaveMilestone::class)->handle($saved, [
                    'status' => $validated['milestoneStatus'],
                    'weight_percentage' => $validated['weightPercentage'] === '' || $validated['weightPercentage'] === null ? $saved->weight_percentage : $validated['weightPercentage'],
                ]);

                $this->linkActivity($saved, $this->milestoneActivityId, 'milestoneActivityId');
            });
        } catch (ValidationException $exception) {
            $fields = [
                'name' => 'name',
                'description' => 'description',
                'planned_start_date' => 'plannedStartDate',
                'planned_end_date' => 'plannedEndDate',
                'responsible_employee_id' => 'responsibleUserId',
                'parent_milestone_id' => 'parentMilestoneId',
                'project_id' => 'parentMilestoneId',
            ];

            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($fields[$key] ?? $key, $message);
                }
            }

            return;
        }

        unset($this->milestone, $this->parentOptions, $this->activityOptions, $this->activityLinkIssues);

        Flux::toast(variant: 'success', text: __('milestones.milestone_updated'));
    }

    public function openRelatedModal(): void
    {
        $this->authorizeRelatedMilestone();

        $this->resetValidation();
        $this->relatedName = '';
        $this->relatedDescription = '';
        $this->relatedPlannedStartDate = '';
        $this->relatedPlannedEndDate = '';
        $this->relatedStatus = 'planned';
        $this->relatedWeightPercentage = '0';
        $this->relatedResponsibleUserId = '';
        $this->relatedParentMilestoneId = (string) $this->milestoneId;
        $this->relatedActivityId = '';
        $this->showRelatedModal = true;
    }

    public function closeRelatedModal(): void
    {
        $this->showRelatedModal = false;
    }

    public function saveRelatedMilestone(): void
    {
        $this->authorizeRelatedMilestone();

        $this->relatedName = trim($this->relatedName);
        $this->relatedDescription = trim($this->relatedDescription);

        $projectId = $this->milestone->project_id;

        $validated = $this->validate([
            'relatedName' => ['required', 'string', 'max:255'],
            'relatedDescription' => ['nullable', 'string'],
            'relatedPlannedStartDate' => ['required', 'date'],
            'relatedPlannedEndDate' => ['nullable', 'date', 'after_or_equal:relatedPlannedStartDate'],
            'relatedStatus' => ['required', new Enum(MilestoneStatus::class)],
            'relatedWeightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'relatedResponsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'relatedParentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $this->constrainToParentCandidates($query),
                ),
            ],
        ], [
            'relatedName.required' => __('milestones.enter_a_milestone_name'),
            'relatedPlannedStartDate.required' => __('milestones.set_the_planned_start_date'),
            'relatedPlannedEndDate.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'relatedResponsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'relatedParentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'relatedWeightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        DB::transaction(function () use ($projectId, $validated): void {
            $created = app(SaveMilestone::class)->handle(null, [
                'project_id' => $projectId,
                'name' => $validated['relatedName'],
                'description' => $validated['relatedDescription'] === '' ? null : $validated['relatedDescription'],
                'planned_start_date' => $validated['relatedPlannedStartDate'],
                'planned_end_date' => $validated['relatedPlannedEndDate'] === '' ? null : $validated['relatedPlannedEndDate'],
                'status' => $validated['relatedStatus'],
                'weight_percentage' => $validated['relatedWeightPercentage'],
                'responsible_user_id' => (int) $validated['relatedResponsibleUserId'],
                'parent_milestone_id' => $validated['relatedParentMilestoneId'] === '' || $validated['relatedParentMilestoneId'] === null
                    ? null
                    : (int) $validated['relatedParentMilestoneId'],
            ]);

            $this->linkActivity($created, $this->relatedActivityId, 'relatedActivityId');
        });

        $this->showRelatedModal = false;
        unset($this->relatedMilestones);
        unset($this->milestone);

        Flux::toast(variant: 'success', text: __('milestones.milestone_created'));
    }

    /** @return array{type: string, id: int} */
    protected function quickTaskSource(): array
    {
        return ['type' => 'milestone', 'id' => $this->milestoneId];
    }

    protected function quickTaskCreated(): void
    {
        unset($this->relatedTasks);
    }

    /**
     * Related milestones are added here for project milestones only: a
     * proposal's milestones are added on its plan, where the plan window and
     * the proposal period apply (SaveProposalMilestone).
     */
    protected function authorizeRelatedMilestone(): void
    {
        abort_if($this->milestone->proposal_id !== null, 403);

        Gate::authorize('create', [Milestone::class, $this->milestone->project_id]);
    }

    /**
     * Limit a milestones query to those this milestone may nest under (see
     * MilestoneObserver): the same proposal (none for a project milestone)
     * and, for a parent in a project, this milestone's project.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Milestone>|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<Milestone>|\Illuminate\Database\Query\Builder
     */
    protected function constrainToParentCandidates(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
    {
        $milestone = $this->milestone;

        return $query
            ->where('proposal_id', $milestone->proposal_id)
            ->where(fn ($query) => $milestone->project_id === null
                ? $query->whereNull('project_id')
                : $query->whereNull('project_id')->orWhere('project_id', $milestone->project_id));
    }

    /** @return array<int, int> */
    protected function invalidParentIds(): array
    {
        $milestone = Milestone::query()
            ->select(['id', 'parent_milestone_id'])
            ->with('childrenRecursive:id,parent_milestone_id,sequence')
            ->find($this->milestoneId);

        if ($milestone === null) {
            return [];
        }

        return [...$milestone->descendants()->modelKeys(), $milestone->id];
    }
};
?>

@php
    $dropdownLabelClasses = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-ink-muted';
    $canEditMilestone = auth()->user()->can('update', $this->milestone);
    $canMoveMilestone = $canEditMilestone || auth()->user()->can('updateProgress', $this->milestone);
    $canAddRelated = $this->milestone->proposal_id === null && auth()->user()->can('create', [App\Models\Milestone::class, $this->milestone->project_id]);
    $selectedParentMilestone = $parentMilestoneId === '' ? null : $this->parentOptions->firstWhere('id', (int) $parentMilestoneId);
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="milestone-detail-heading">
    <x-ui.detail-header
        id="milestone-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.milestones'), 'url' => route('milestones.index')],
            ['label' => $this->milestone->name],
        ]"
        :title="$this->milestone->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'milestone', $this->milestone->id)"
        title-class="text-3xl! font-bold! leading-none!"
        :subtitle="$this->milestone->ownerName()"
    >
        <x-slot:meta>
            <x-ui.enum-badge :case="$this->milestone->status" />
            <x-ui.milestone-flags :milestone="$this->milestone" />
            @if ($this->milestone->parent !== null)
                <span>{{ __('milestones.nested_milestone') }}: {{ $this->milestone->parent->name }}</span>
            @endif
            <span>{{ __('milestones.responsible') }}: {{ $this->milestone->responsibleUser->name }} · {{ $this->milestone->responsibleUser->secondary_name }}</span>
            <span>{{ __('common.updated_date', ['date' => $this->milestone->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3 @4xl/detail:grid-cols-6">
        <x-ui.stat-tile :label="__('milestones.weight')" :value="rtrim(rtrim(number_format((float) $this->milestone->weight_percentage, 2), '0'), '.').'%'" icon="scale" />
        <x-ui.stat-tile :label="__('milestones.planned_start_date')" :value="$this->milestone->planned_start_date->translatedFormat(__('formats.date'))" icon="calendar" />
        <x-ui.stat-tile :label="__('milestones.planned_end_date')" :value="$this->milestone->planned_end_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar" />
        <x-ui.stat-tile :label="__('milestones.actual_start_date')" :value="$this->milestone->actual_start_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar-days" />
        <x-ui.stat-tile :label="__('milestones.actual_end_date')" :value="$this->milestone->actual_end_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar-days" />
        <x-ui.stat-tile :label="__('milestones.sequence')" :value="$this->milestone->sequence" icon="hashtag" />
    </div>

    @if ($canMoveMilestone)
    <x-ui.panel :title="__('milestones.edit_milestone')" :subtitle="__('milestones.edit_milestone_hint')">
        <form wire:submit="updateMilestone" class="flex flex-col gap-5 p-4">
            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus :disabled="! $canEditMilestone" />

            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" :disabled="! $canEditMilestone" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-field-line! dark:bg-surface-2! dark:text-ink!" />

            <div class="flex flex-col gap-4 border-t border-secondary-soft pt-5 dark:border-line">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('milestones.schedule_and_status') }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="plannedStartDate" :label="__('milestones.planned_start_date')" type="date" required :disabled="! $canEditMilestone" />
                    <x-ui.input wire:model="plannedEndDate" :label="__('milestones.planned_end_date')" type="date" :disabled="! $canEditMilestone" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="min-w-0">
                        <label for="milestone-edit-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                        <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                            <flux:button id="milestone-edit-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                                <span class="truncate">{{ MilestoneStatus::from($milestoneStatus)->label() }}</span>
                            </flux:button>
                            <flux:menu class="filter-menu">
                                @foreach (MilestoneStatus::cases() as $statusCase)
                                    <flux:menu.item wire:click="$set('milestoneStatus', '{{ $statusCase->value }}')" :wire:key="'milestone-edit-status-option-'.$statusCase->value">
                                        <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                        @if ($milestoneStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                    </flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                        <flux:error name="milestoneStatus" class="mt-1.5" />
                    </div>

                    <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required :disabled="! $canEditMilestone" />
                </div>
            </div>

            <div class="flex flex-col gap-4 border-t border-secondary-soft pt-5 dark:border-line">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('milestones.assignment') }}</p>

                <div class="min-w-0">
                    <x-ui.progress-bar :value="(float) $weightPercentage" :max="100" show-value class="mt-2" />
                </div>

                <div class="min-w-0">
                    <x-ui.form-select id="milestone-edit-responsible" wire:model="responsibleUserId" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')" :disabled="! $canEditMilestone" employees />
                </div>

                <div class="min-w-0 sm:max-w-xs">
                    <label for="milestone-edit-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-edit-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')" :disabled="! $canEditMilestone">
                            <span class="truncate">{{ $selectedParentMilestone?->name ?? __('common.top_level') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->parentOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-line">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_milestones')" :placeholder="__('milestones.search_milestones')" />
                                </div>
                            @endif
                            <flux:menu.item wire:click="$set('parentMilestoneId', '')">
                                {{ __('common.top_level') }}
                                @if ($parentMilestoneId === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                            </flux:menu.item>
                            @foreach ($this->parentOptions as $parentOption)
                                <flux:menu.item wire:click="$set('parentMilestoneId', {{ $parentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-edit-parent-option-'.$parentOption->id">
                                    <span class="min-w-0 truncate">{{ $parentOption->name }}</span>
                                    @if ((int) $parentMilestoneId === $parentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="parentMilestoneId" class="mt-1.5" />
                </div>

                <div class="min-w-0 sm:max-w-xl">
                    <x-milestones.activity-select id="milestone-edit-activity" wire:model="milestoneActivityId" :options="$this->activityOptions" :issues="$this->activityLinkIssues" :disabled="! $canEditMilestone" data-test="milestone-activity-select" />
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-line">
                <x-ui.button type="submit" tone="primary" class="min-h-11 touch-manipulation sm:min-h-10" data-test="update-milestone-button">{{ __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
    @endif

    <x-ui.panel :title="__('milestones.related_milestones')" :count="$this->relatedMilestones->count()">
        <x-slot:toolbar>
            @if ($canAddRelated)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openRelatedModal" data-test="add-related-milestone-button">{{ __('milestones.add_related_milestone') }}</x-ui.button>@endif
        </x-slot:toolbar>

        @if ($this->relatedMilestones->isEmpty())
            <x-ui.empty-state icon="flag" :heading="__('milestones.no_related_milestones_yet')" :body="__('milestones.add_a_milestone_nested_under_this_one')">
                <x-slot:action>
                    @if ($canAddRelated)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openRelatedModal">{{ __('milestones.add_related_milestone') }}</x-ui.button>@endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->relatedMilestones as $related)
                    <li class="flex items-center justify-between gap-3 p-3.5" wire:key="related-milestone-{{ $related->id }}">
                        <a href="{{ route('milestones.show', $related) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold hover:underline">{{ $related->name }}</a>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ $related->planned_start_date->translatedFormat(__('formats.date')) }}</span>
                            <x-ui.enum-badge :case="$related->status" />
                            <x-ui.milestone-flags :milestone="$related" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <x-ui.panel :title="__('tasks.related_tasks')" :count="$this->relatedTasks->count()">
        <x-slot:toolbar>
            @can('create', App\Models\Task::class)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddTaskModal" data-test="add-milestone-task-button">{{ __('tasks.add_task') }}</x-ui.button>@endcan
        </x-slot:toolbar>

        @if ($this->relatedTasks->isEmpty())
            <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('milestones.add_a_task_linked_to_this_milestone')">
                <x-slot:action>
                    @can('create', App\Models\Task::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>@endcan
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->relatedTasks as $relatedTask)
                    @php $relatedTaskAssignees = $relatedTask->taskAssignees->pluck('employee')->filter(); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 p-3.5" wire:key="milestone-related-task-{{ $relatedTask->id }}">
                        <a href="{{ route('tasks.show', $relatedTask) }}" wire:navigate class="min-w-0 flex-1 truncate text-sm font-semibold hover:underline">
                            {{ $relatedTask->title }}
                        </a>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="w-40 shrink-0 truncate text-xs text-secondary-medium dark:text-ink-muted">
                                {{ $relatedTaskAssignees->isEmpty() ? __('common.not_assigned') : $relatedTaskAssignees->map->name->implode(', ') }}
                            </span>
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                {{ $relatedTask->due_date?->translatedFormat(__('formats.date')) ?? '—' }}
                            </span>
                            <x-ui.enum-badge :case="$relatedTask->status" />
                            <x-ui.enum-badge :case="$relatedTask->priority" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <x-tasks.add-task-modal :open="$showAddTaskModal" test-prefix="new-milestone-task" />

    <section aria-labelledby="record-activity-heading" class="grid gap-3" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['milestone' => [$this->milestone->id]]" :key="'milestone-activity-'.$this->milestone->id" />
    </section>

    <x-ui.modal
        wire:model="showRelatedModal"
        size="xl"
        :title="__('milestones.add_related_milestone')"
        submit="saveRelatedMilestone"
        close="closeRelatedModal"
        required-note
    >
        @if ($showRelatedModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['relatedName', 'relatedDescription']">
                <div class="grid gap-y-3">
                    <x-ui.form-input wire:model="relatedName" :label="__('milestones.milestone_name')" maxlength="255" required autofocus />
                    <x-ui.form-textarea wire:model="relatedDescription" :label="__('common.description')" rows="2" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_placement')" :fields="['relatedParentMilestoneId', 'relatedResponsibleUserId', 'relatedActivityId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-select wire:model="relatedParentMilestoneId" :label="__('milestones.nest_under')" :search-label="__('milestones.search_milestones')">
                        <option value="">{{ __('common.top_level') }}</option>
                        @foreach ($this->relatedParentOptions as $relatedParentOption)
                            <option value="{{ $relatedParentOption->id }}">{{ $relatedParentOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="relatedResponsibleUserId" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')" employees />

                    <div class="@2xl/modal:col-span-2">
                        <x-milestones.activity-select wire:model="relatedActivityId" :options="$this->relatedActivityOptions" />
                    </div>
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('common.section_schedule')" :fields="['relatedPlannedStartDate', 'relatedPlannedEndDate', 'relatedStatus', 'relatedWeightPercentage']">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                    <x-ui.form-input wire:model="relatedPlannedStartDate" :label="__('milestones.planned_start_date')" type="date" required />
                    <x-ui.form-input wire:model="relatedPlannedEndDate" :label="__('milestones.planned_end_date')" type="date" />

                    <x-ui.form-select wire:model="relatedStatus" :label="__('common.status')" required>
                        @foreach (MilestoneStatus::cases() as $statusCase)
                            <option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="relatedWeightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" suffix="%" required />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-related-milestone-button">{{ __('milestones.create_milestone') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
