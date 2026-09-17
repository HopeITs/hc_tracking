<?php

use App\Enums\MilestoneStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.calendar')] class extends Component {
    #[Url(except: '')]
    public string $month = '';

    public bool $showMilestoneModal = false;
    public bool $showDeleteModal = false;
    public ?int $editingMilestoneId = null;
    public ?int $deletingMilestoneId = null;
    public string $name = '';
    public string $plannedDate = '';
    public string $actualDate = '';
    public string $milestoneStatus = 'pending';
    public string $sequence = '1';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $milestoneProjectId = '';
    public string $parentMilestoneId = '';

    public function previousMonth(): void
    {
        $this->month = $this->activeMonth()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->activeMonth()->addMonthNoOverflow()->format('Y-m');
    }

    public function goToToday(): void
    {
        $this->month = '';
    }

    /**
     * The employee record for the signed-in user, if their account is linked
     * to one. `Milestone::responsible_user_id` holds an HR employee id, not a
     * `users.id`, so this is the bridge between "me" and "my milestones".
     */
    protected function employeeId(): ?int
    {
        return auth()->user()->employee_id;
    }

    protected function activeMonth(): CarbonImmutable
    {
        if ($this->month !== '' && preg_match('/^\d{4}-\d{2}$/', $this->month) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01');

            if ($parsed !== false) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * The calendar grid: full weeks covering the active month, so the first
     * and last rows are padded with the tail of the adjacent months.
     *
     * @return array{month: CarbonImmutable, weeks: array<int, array<int, CarbonImmutable>>, milestonesByDay: Collection<string, Collection<int, Milestone>>}
     */
    #[Computed]
    public function calendar(): array
    {
        $month = $this->activeMonth();
        $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor->lte($gridEnd)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $cursor;
                $cursor = $cursor->addDay();
            }
            $weeks[] = $week;
        }

        $employeeId = $this->employeeId();

        $milestonesByDay = $employeeId === null
            ? new Collection
            : Milestone::query()
                ->select(['id', 'project_id', 'name', 'planned_date', 'status', 'weight_percentage', 'responsible_user_id'])
                ->where('responsible_user_id', $employeeId)
                ->whereBetween('planned_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
                ->with('project:id,name')
                ->withCount(['children', 'tasks'])
                ->orderBy('planned_date')
                ->orderBy('sequence')
                ->get()
                ->groupBy(fn (Milestone $milestone): string => $milestone->planned_date->toDateString());

        return [
            'month' => $month,
            'weeks' => $weeks,
            'milestonesByDay' => $milestonesByDay,
        ];
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
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
        if ($this->milestoneProjectId === '') {
            return new Collection;
        }

        $invalidParentIds = $this->invalidParentIds();

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', $this->parentProjectId())
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
        }

        unset($this->parentOptions);
    }

    public function openCreateModal(?string $date = null): void
    {
        $this->resetMilestoneForm();

        if ($date !== null) {
            $this->plannedDate = $date;
        }

        $this->responsibleUserId = (string) ($this->employeeId() ?? '');
        $this->showMilestoneModal = true;
    }

    public function openEditModal(int $milestoneId): void
    {
        $milestone = Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->findOrFail($milestoneId);

        $this->resetValidation();
        $this->editingMilestoneId = $milestone->id;
        $this->name = $milestone->name;
        $this->plannedDate = $milestone->planned_date->toDateString();
        $this->actualDate = $milestone->actual_date?->toDateString() ?? '';
        $this->milestoneStatus = $milestone->status->value;
        $this->sequence = (string) $milestone->sequence;
        $this->weightPercentage = $milestone->weight_percentage;
        $this->responsibleUserId = (string) $milestone->responsible_user_id;
        $this->milestoneProjectId = (string) $milestone->project_id;
        $this->parentMilestoneId = (string) ($milestone->parent_milestone_id ?? '');
        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $this->name = trim($this->name);

        $existingMilestone = $this->editingMilestoneId === null
            ? null
            : Milestone::query()->findOrFail($this->editingMilestoneId);
        $projectId = $existingMilestone?->project_id ?? (int) $this->milestoneProjectId;
        $invalidParentIds = $this->invalidParentIds();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'plannedDate' => ['required', 'date'],
            'actualDate' => ['nullable', 'date'],
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
                    fn ($query) => $query->where('project_id', $projectId),
                ),
                Rule::notIn($invalidParentIds),
            ],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            'plannedDate.required' => __('milestones.set_the_planned_date'),
            'responsibleUserId.required' => __('milestones.select_the_responsible_staff_member'),
            'milestoneProjectId.required' => __('milestones.select_the_project'),
            'milestoneProjectId.in' => __('milestones.a_milestone_cannot_be_moved_to_another_project'),
            'parentMilestoneId.exists' => __('milestones.select_a_milestone_from_this_project'),
            'parentMilestoneId.not_in' => __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'),
            'weightPercentage.max' => __('milestones.weight_cannot_exceed_100_percent'),
        ]);

        $milestone = $existingMilestone ?? new Milestone;
        $attributes = [
            'name' => $validated['name'],
            'planned_date' => $validated['plannedDate'],
            'actual_date' => $validated['actualDate'] === '' ? null : $validated['actualDate'],
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

        $milestone->fill($attributes)->save();
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
        $this->deletingMilestoneId = $milestoneId;
        $this->showDeleteModal = true;
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        $milestone = Milestone::query()->find($this->deletingMilestoneId);
        $deleted = $milestone !== null && $milestone->children()->doesntExist() && $milestone->tasks()->doesntExist()
            ? $milestone->delete()
            : false;

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
        $this->plannedDate = '';
        $this->actualDate = '';
        $this->milestoneStatus = 'pending';
        $this->sequence = '1';
        $this->weightPercentage = '0';
        $this->responsibleUserId = '';
        $this->milestoneProjectId = '';
        $this->parentMilestoneId = '';
        unset($this->parentOptions);
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
};
?>

@php
    $calendar = $this->calendar;
    $today = \Carbon\CarbonImmutable::today();
    $hasEmployee = auth()->user()->employee_id !== null;

    $markerTone = fn (MilestoneStatus $status): string => match ($status) {
        MilestoneStatus::Reached => 'bg-status-good-soft text-status-good',
        MilestoneStatus::Missed => 'bg-status-critical-soft text-primary',
        MilestoneStatus::Cancelled => 'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft',
        MilestoneStatus::Pending => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium',
    };

    $selectedMilestoneProject = $milestoneProjectId === '' ? null : $this->projectOptions->firstWhere('id', (int) $milestoneProjectId);
    $selectedParentMilestone = $parentMilestoneId === '' ? null : $this->parentOptions->firstWhere('id', (int) $parentMilestoneId);
    $selectedResponsibleOption = $responsibleUserId === '' ? null : $this->userOptions->firstWhere('id', (int) $responsibleUserId);
    $dropdownLabelClasses = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-secondary-soft';
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="calendar-heading">
    <x-ui.page-header heading-id="calendar-heading" :title="__('common.my_calendar')">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.button size="sm" wire:click="previousMonth" :aria-label="__('common.previous')">
                    <flux:icon.chevron-left variant="micro" class="size-4 rtl:-scale-x-100" />
                </x-ui.button>
                <x-ui.button size="sm" wire:click="goToToday">{{ __('common.today') }}</x-ui.button>
                <x-ui.button size="sm" wire:click="nextMonth" :aria-label="__('common.next')">
                    <flux:icon.chevron-right variant="micro" class="size-4 rtl:-scale-x-100" />
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ $calendar['month']->translatedFormat(__('formats.month_year')) }}</flux:heading>
        <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" data-test="calendar-add-milestone-button">{{ __('milestones.add_milestone') }}</x-ui.button>
    </div>

    @if (! $hasEmployee)
        <x-ui.panel>
            <x-ui.empty-state icon="user" :heading="__('calendar.no_linked_employee')" :body="__('calendar.link_your_account_to_see_your_calendar')" />
        </x-ui.panel>
    @else
        <div class="overflow-hidden rounded-xl border border-secondary-soft dark:border-secondary-medium">
            <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-xs font-semibold uppercase tracking-wide text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/40 dark:text-secondary-soft">
                @foreach ($calendar['weeks'][0] as $weekdayHeader)
                    <div class="py-2">{{ $weekdayHeader->translatedFormat('D') }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7">
                @foreach ($calendar['weeks'] as $week)
                    @foreach ($week as $day)
                        @php
                            $dayMilestones = $calendar['milestonesByDay']->get($day->toDateString(), new \Illuminate\Support\Collection);
                            $isCurrentMonth = $day->month === $calendar['month']->month;
                            $isToday = $day->isSameDay($today);
                        @endphp
                        <div
                            wire:click="openCreateModal('{{ $day->toDateString() }}')"
                            wire:key="calendar-day-{{ $day->toDateString() }}"
                            class="flex min-h-28 cursor-pointer flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start last:border-e-0 [&:nth-child(7n)]:border-e-0 hover:bg-secondary-soft/40 dark:border-secondary-medium dark:hover:bg-secondary-medium/40 {{ $isCurrentMonth ? '' : 'opacity-40' }}"
                            data-test="calendar-day-{{ $day->toDateString() }}"
                        >
                            <span @class([
                                'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                'bg-primary text-white' => $isToday,
                                'text-secondary-dark dark:text-white' => ! $isToday,
                            ])>
                                {{ $day->day }}
                            </span>

                            @foreach ($dayMilestones as $milestone)
                                <div wire:key="calendar-milestone-{{ $milestone->id }}" class="group flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $markerTone($milestone->status) }}">
                                    <button type="button" wire:click.stop="openEditModal({{ $milestone->id }})" class="min-w-0 flex-1 cursor-pointer truncate text-start font-medium" data-test="calendar-milestone-{{ $milestone->id }}">
                                        {{ $milestone->name }}
                                    </button>
                                    @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
                                        <button type="button" wire:click.stop="confirmDelete({{ $milestone->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                                            <flux:icon.x-mark variant="micro" class="size-3" />
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    @endif

    <flux:modal wire:model="showMilestoneModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveMilestone" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingMilestoneId === null ? __('milestones.add_milestone') : __('milestones.edit_milestone') }}</flux:heading>
            </div>

            <x-ui.input wire:model="name" :label="__('milestones.milestone_name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="milestone-form-project" class="{{ $dropdownLabelClasses }}">{{ __('common.project') }}</label>
                    @if ($editingMilestoneId !== null)
                        <flux:tooltip :content="__('milestones.a_milestone_cannot_be_moved_to_another_project')">
                            @include('pages.milestones._project-dropdown', ['disabled' => true])
                        </flux:tooltip>
                    @else
                        @include('pages.milestones._project-dropdown', ['disabled' => false])
                    @endif
                    <flux:error name="milestoneProjectId" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <label for="milestone-form-parent" class="{{ $dropdownLabelClasses }}">{{ __('milestones.nest_under') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-form-parent" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.nest_under')">
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
                                <flux:menu.item wire:click="$set('parentMilestoneId', {{ $parentOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-parent-option-'.$parentOption->id">
                                    <span class="min-w-0 truncate">{{ $parentOption->name }}</span>
                                    @if ((int) $parentMilestoneId === $parentOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="parentMilestoneId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedDate" :label="__('milestones.planned_date_2')" type="date" required data-test="calendar-form-planned-date" />
                <x-ui.input wire:model="actualDate" :label="__('milestones.actual_date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label for="milestone-form-status" class="{{ $dropdownLabelClasses }}">{{ __('common.status') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
                        <flux:button id="milestone-form-status" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('common.status')">
                            <span class="truncate">{{ MilestoneStatus::from($milestoneStatus)->label() }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @foreach (MilestoneStatus::cases() as $statusCase)
                                <flux:menu.item wire:click="$set('milestoneStatus', '{{ $statusCase->value }}')" :wire:key="'milestone-form-status-option-'.$statusCase->value">
                                    <span class="min-w-0 truncate">{{ $statusCase->label() }}</span>
                                    @if ($milestoneStatus === $statusCase->value)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="milestoneStatus" class="mt-1.5" />
                </div>

                <x-ui.input wire:model="sequence" :label="__('milestones.sequence')" type="number" min="0" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" required />

                <div class="min-w-0">
                    <label for="milestone-form-responsible" class="{{ $dropdownLabelClasses }}">{{ __('milestones.responsible') }}</label>
                    <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!" x-data="{ optionSearch: '' }">
                        <flux:button id="milestone-form-responsible" type="button" variant="outline" icon:trailing="chevron-up-down" class="w-full! justify-between!" :aria-label="__('milestones.responsible')">
                            <span class="truncate">{{ $selectedResponsibleOption ? $selectedResponsibleOption->name.' · '.$selectedResponsibleOption->secondary_name : __('common.select') }}</span>
                        </flux:button>
                        <flux:menu class="filter-menu">
                            @if ($this->userOptions->count() > 6)
                                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                                    <x-ui.input type="search" size="sm" icon="magnifying-glass" x-model="optionSearch" :aria-label="__('milestones.search_responsible')" :placeholder="__('milestones.search_responsible')" />
                                </div>
                            @endif
                            @foreach ($this->userOptions as $userOption)
                                <flux:menu.item wire:click="$set('responsibleUserId', {{ $userOption->id }})" x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())" :wire:key="'milestone-form-responsible-option-'.$userOption->id">
                                    <span class="min-w-0 truncate">{{ $userOption->name }} · {{ $userOption->secondary_name }}</span>
                                    @if ((int) $responsibleUserId === $userOption->id)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <flux:error name="responsibleUserId" class="mt-1.5" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeMilestoneModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="calendar-save-milestone-button">{{ $editingMilestoneId === null ? __('milestones.create_milestone') : __('common.save_changes') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('milestones.delete_this_milestone') }}</flux:heading>
            <flux:subheading>{{ __('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can') }}</flux:subheading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('showDeleteModal', false)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteMilestone" data-test="calendar-confirm-delete-milestone">{{ __('milestones.delete_milestone') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
