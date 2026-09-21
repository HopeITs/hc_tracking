<?php

use App\Enums\MilestoneStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.milestone')] class extends Component {
    #[Locked]
    public int $milestoneId;

    public string $name = '';
    public string $description = '';
    public string $plannedDate = '';
    public string $actualDate = '';
    public string $milestoneStatus = 'pending';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $parentMilestoneId = '';

    public bool $showRelatedModal = false;
    public string $relatedName = '';
    public string $relatedDescription = '';
    public string $relatedPlannedDate = '';
    public string $relatedActualDate = '';
    public string $relatedStatus = 'pending';
    public string $relatedWeightPercentage = '0';
    public string $relatedResponsibleUserId = '';
    public string $relatedParentMilestoneId = '';

    public function mount(Milestone $milestone): void
    {
        $this->milestoneId = $milestone->id;

        $freshMilestone = $this->milestone;
        $this->name = $freshMilestone->name;
        $this->description = $freshMilestone->description ?? '';
        $this->plannedDate = $freshMilestone->planned_date->toDateString();
        $this->actualDate = $freshMilestone->actual_date?->toDateString() ?? '';
        $this->milestoneStatus = $freshMilestone->status->value;
        $this->weightPercentage = $freshMilestone->weight_percentage;
        $this->responsibleUserId = (string) $freshMilestone->responsible_user_id;
        $this->parentMilestoneId = (string) ($freshMilestone->parent_milestone_id ?? '');
    }

    #[Computed]
    public function milestone(): Milestone
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'name', 'description', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id', 'updated_at'])
            ->with(['project:id,name', 'responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar', 'parent:id,name'])
            ->findOrFail($this->milestoneId);
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function relatedMilestones(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'status', 'planned_date', 'sequence'])
            ->where('parent_milestone_id', $this->milestoneId)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function parentOptions(): Collection
    {
        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->milestone->project_id)
            ->whereNotIn('id', $invalidParentIds)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function relatedParentOptions(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->milestone->project_id)
            ->orderBy('sequence')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function updateMilestone(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $milestone = Milestone::query()->findOrFail($this->milestoneId);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedDate' => ['required', 'date'],
            'actualDate' => ['nullable', 'date'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'parentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $milestone->project_id),
                ),
                Rule::notIn($this->invalidParentIds()),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedDate.required' => __('milestones.set_the_planned_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $milestone->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'planned_date' => $validated['plannedDate'],
            'actual_date' => $validated['actualDate'] === '' ? null : $validated['actualDate'],
            'status' => $validated['milestoneStatus'],
            'weight_percentage' => $validated['weightPercentage'],
            'responsible_user_id' => (int) $validated['responsibleUserId'],
            'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                ? null
                : (int) $validated['parentMilestoneId'],
        ])->save();

        unset($this->milestone);
        unset($this->parentOptions);

        Flux::toast(variant: 'success', text: __('milestones.milestone_updated'));
    }

    public function openRelatedModal(): void
    {
        $this->resetValidation();
        $this->relatedName = '';
        $this->relatedDescription = '';
        $this->relatedPlannedDate = '';
        $this->relatedActualDate = '';
        $this->relatedStatus = 'pending';
        $this->relatedWeightPercentage = '0';
        $this->relatedResponsibleUserId = '';
        $this->relatedParentMilestoneId = (string) $this->milestoneId;
        $this->showRelatedModal = true;
    }

    public function closeRelatedModal(): void
    {
        $this->showRelatedModal = false;
    }

    public function saveRelatedMilestone(): void
    {
        $this->relatedName = trim($this->relatedName);
        $this->relatedDescription = trim($this->relatedDescription);

        $projectId = $this->milestone->project_id;

        $validated = $this->validate([
            'relatedName' => ['required', 'string', 'max:255'],
            'relatedDescription' => ['nullable', 'string'],
            'relatedPlannedDate' => ['required', 'date'],
            'relatedActualDate' => ['nullable', 'date'],
            'relatedStatus' => ['required', new Enum(MilestoneStatus::class)],
            'relatedWeightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'relatedResponsibleUserId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'relatedParentMilestoneId' => [
                'nullable',
                'integer',
                Rule::exists(Milestone::class, 'id')->where(
                    fn ($query) => $query->where('project_id', $projectId),
                ),
            ],
        ], [
            'relatedName.required' => __('milestones.enter_a_milestone_name'),
            'relatedPlannedDate.required' => __('milestones.set_the_planned_date'),
            'relatedResponsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'relatedParentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'relatedWeightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        Milestone::query()->create([
            'project_id' => $projectId,
            'name' => $validated['relatedName'],
            'description' => $validated['relatedDescription'] === '' ? null : $validated['relatedDescription'],
            'planned_date' => $validated['relatedPlannedDate'],
            'actual_date' => $validated['relatedActualDate'] === '' ? null : $validated['relatedActualDate'],
            'status' => $validated['relatedStatus'],
            'weight_percentage' => $validated['relatedWeightPercentage'],
            'responsible_user_id' => (int) $validated['relatedResponsibleUserId'],
            'parent_milestone_id' => $validated['relatedParentMilestoneId'] === '' || $validated['relatedParentMilestoneId'] === null
                ? null
                : (int) $validated['relatedParentMilestoneId'],
        ]);

        $this->showRelatedModal = false;
        unset($this->relatedMilestones);
        unset($this->milestone);

        Flux::toast(variant: 'success', text: __('milestones.milestone_created'));
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
    $dropdownLabelClasses = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft';
    $selectedParentMilestone = $parentMilestoneId === '' ? null : $this->parentOptions->firstWhere('id', (int) $parentMilestoneId);
    $selectedResponsibleOption = $responsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $responsibleUserId);
    $selectedRelatedParentMilestone = $relatedParentMilestoneId === '' ? null : $this->relatedParentOptions->firstWhere('id', (int) $relatedParentMilestoneId);
    $selectedRelatedResponsibleOption = $relatedResponsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $relatedResponsibleUserId);
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="milestone-detail-heading">
    <x-ui.detail-header
        id="milestone-detail-heading"
        :breadcrumbs="[
            ['label' => __('common.milestones'), 'url' => route('milestones.index')],
            ['label' => $this->milestone->name],
        ]"
        :title="$this->milestone->name"
        title-class="text-3xl! font-bold! leading-none!"
        :subtitle="$this->milestone->project->name"
    >
        <x-slot:meta>
            <x-ui.enum-badge :case="$this->milestone->status" />
            @if ($this->milestone->parent !== null)
                <span>{{ __('milestones.nested_milestone') }}: {{ $this->milestone->parent->name }}</span>
            @endif
            <span>{{ __('milestones.responsible') }}: {{ $this->milestone->responsibleUser->name }} · {{ $this->milestone->responsibleUser->secondary_name }}</span>
            <span>{{ __('common.updated_date', ['date' => $this->milestone->updated_at?->translatedFormat(__('formats.date'))]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('milestones.weight')" :value="rtrim(rtrim(number_format((float) $this->milestone->weight_percentage, 2), '0'), '.').'%'" icon="scale" />
        <x-ui.stat-tile :label="__('milestones.planned_date_2')" :value="$this->milestone->planned_date->translatedFormat(__('formats.date'))" icon="calendar" />
        <x-ui.stat-tile :label="__('milestones.actual_date')" :value="$this->milestone->actual_date?->translatedFormat(__('formats.date')) ?? '—'" icon="calendar-days" />
        <x-ui.stat-tile :label="__('milestones.sequence')" :value="$this->milestone->sequence" icon="hashtag" />
    </div>

    <x-ui.panel :title="__('milestones.edit_milestone')" :subtitle="__('milestones.edit_milestone_hint')">
        <form wire:submit="updateMilestone" class="flex flex-col gap-5 p-4">
            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="flex flex-col gap-4 border-t border-secondary-soft pt-5 dark:border-secondary-medium">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('milestones.schedule_and_status') }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="plannedDate" :label="__('milestones.planned_date_2')" type="date" required />
                    <x-ui.input wire:model="actualDate" :label="__('milestones.actual_date')" type="date" />
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

                    <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />
                </div>
            </div>

            <div class="flex flex-col gap-4 border-t border-secondary-soft pt-5 dark:border-secondary-medium">
                <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('milestones.assignment') }}</p>

                <div class="min-w-0">
                    <x-ui.progress-bar :value="(float) $weightPercentage" :max="100" show-value class="mt-2" />
                </div>

                <div class="min-w-0">
                    <label for="milestone-edit-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-edit-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedResponsibleOption ? $selectedResponsibleOption->name.' · '.$selectedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('responsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-edit-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $responsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="responsibleUserId" class="mt-1.5" />
                </div>

                <div class="min-w-0 sm:max-w-xs">
                    <label for="milestone-edit-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-edit-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
                            <span class="truncate">{{ $selectedParentMilestone?->name ?? __('common.top_level') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->parentOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
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
            </div>

            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="submit" tone="primary" class="min-h-11 touch-manipulation sm:min-h-10" data-test="update-milestone-button">{{ __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-ui.panel :title="__('milestones.related_milestones')" :count="$this->relatedMilestones->count()">
        <x-slot:toolbar>
            <x-ui.button tone="primary" size="sm" icon="plus" wire:click="openRelatedModal" data-test="add-related-milestone-button">{{ __('milestones.add_related_milestone') }}</x-ui.button>
        </x-slot:toolbar>

        @if ($this->relatedMilestones->isEmpty())
            <x-ui.empty-state icon="flag" :heading="__('milestones.no_related_milestones_yet')" :body="__('milestones.add_a_milestone_nested_under_this_one')">
                <x-slot:action>
                    <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openRelatedModal">{{ __('milestones.add_related_milestone') }}</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->relatedMilestones as $related)
                    <li class="flex items-center justify-between gap-3 p-3.5" wire:key="related-milestone-{{ $related->id }}">
                        <a href="{{ route('milestones.show', $related) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold hover:underline">{{ $related->name }}</a>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $related->planned_date->translatedFormat(__('formats.date')) }}</span>
                            <x-ui.enum-badge :case="$related->status" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>

    <flux:modal wire:model="showRelatedModal" wire:close="closeRelatedModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveRelatedMilestone" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('milestones.add_related_milestone') }}</flux:heading>
            </div>

            <x-ui.input wire:model="relatedName" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <div class="min-w-0">
                <label for="related-milestone-form-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                    <flux:button id="related-milestone-form-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
                        <span class="truncate">{{ $selectedRelatedParentMilestone?->name ?? __('common.top_level') }}</span>
                    </flux:button>
                    <flux:menu class="filter-menu">
                        @if ($this->relatedParentOptions->count() > 6)
                            <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_milestones')" :placeholder="__('milestones.search_milestones')" />
                            </div>
                        @endif
                        <flux:menu.item wire:click="$set('relatedParentMilestoneId', '')">
                            {{ __('common.top_level') }}
                            @if ($relatedParentMilestoneId === '')<flux:icon.check class="ms-auto size-4 text-secondary-medium" />@endif
                        </flux:menu.item>
                        @foreach ($this->relatedParentOptions as $relatedParentOption)
                            <flux:menu.item wire:click="$set('relatedParentMilestoneId', {{ $relatedParentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'related-milestone-form-parent-option-'.$relatedParentOption->id">
                                <span class="min-w-0 truncate">{{ $relatedParentOption->name }}</span>
                                @if ((int) $relatedParentMilestoneId === $relatedParentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                <flux:error name="relatedParentMilestoneId" class="mt-1.5" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="relatedPlannedDate" :label="__('milestones.planned_date_2')" type="date" required />
                <x-ui.input wire:model="relatedActualDate" :label="__('milestones.actual_date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="related-milestone-form-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="related-milestone-form-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                            <span class="truncate">{{ MilestoneStatus::from($relatedStatus)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (MilestoneStatus::cases() as $statusCase)
                                <flux:menu.item wire:click="$set('relatedStatus', '{{ $statusCase->value }}')" :wire:key="'related-milestone-form-status-option-'.$statusCase->value">
                                    <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                    @if ($relatedStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="relatedStatus" class="mt-1.5" />
                </div>

                <x-ui.input wire:model="relatedWeightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="related-milestone-form-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="related-milestone-form-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedRelatedResponsibleOption ? $selectedRelatedResponsibleOption->name.' · '.$selectedRelatedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('relatedResponsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'related-milestone-form-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $relatedResponsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="relatedResponsibleUserId" class="mt-1.5" />
                </div>
            </div>

            <flux:textarea wire:model="relatedDescription" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeRelatedModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-related-milestone-button">{{ __('milestones.create_milestone') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
