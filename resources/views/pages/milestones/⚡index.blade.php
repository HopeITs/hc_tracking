<?php

use App\Enums\MilestoneStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Scopes\TaskVisibilityScope;
use App\Notifications\UnreadSubjects;
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
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.milestones')] class extends Component {
    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $responsible = '';

    #[Url(as: 'view', except: 'timeline')]
    public string $view = 'timeline';

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $anchorDate = '';

    #[Url(as: 'period', except: 'month')]
    public string $viewMode = 'month';

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

    public function clearFilters(): void
    {
        $this->project = '';
        $this->status = '';
        $this->responsible = '';
    }

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
        $this->anchorDate = '';
    }

    /**
     * Switch between the month grid and the ClickUp-style day/4-days/week
     * time-grid views, mirroring the "My Calendar" page. Only the four
     * values below are valid view modes; any other value (e.g. a stale
     * bookmarked URL) is silently ignored so the component falls back to
     * whatever $viewMode already holds.
     */
    public function setViewMode(string $viewMode): void
    {
        if (! in_array($viewMode, ['day', 'four_days', 'week', 'month'], true)) {
            return;
        }

        $this->viewMode = $viewMode;
    }

    public function previousPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->subDays($this->periodLengthInDays())->format('Y-m-d');
    }

    public function nextPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->addDays($this->periodLengthInDays())->format('Y-m-d');
    }

    protected function periodLengthInDays(): int
    {
        return match ($this->viewMode) {
            'day' => 1,
            'four_days' => 4,
            'week' => 7,
            default => 1,
        };
    }

    protected function activeAnchor(): CarbonImmutable
    {
        if ($this->anchorDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->anchorDate) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->anchorDate);

            if ($parsed !== false) {
                return $parsed->startOfDay();
            }
        }

        return CarbonImmutable::now()->startOfDay();
    }

    /**
     * The visible days for the day/4-days/week views. Week always starts on
     * the anchor's Sunday, so navigating never lands mid-week.
     *
     * @return array<int, CarbonImmutable>
     */
    protected function periodDays(): array
    {
        $anchor = $this->viewMode === 'week'
            ? $this->activeAnchor()->startOfWeek(CarbonImmutable::SUNDAY)
            : $this->activeAnchor();

        return array_map(
            fn (int $i): CarbonImmutable => $anchor->addDays($i),
            range(0, $this->periodLengthInDays() - 1),
        );
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
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'milestone');
    }

    /**
     * The calendar grid for the calendar view, built from the same filtered
     * milestones as the other views so switching views never changes what's
     * shown — just how. In month view, full weeks covering the active month
     * (padded with the tail of adjacent months). In day/4-days/week view,
     * the plain list of visible days instead — `weeks` is null then and
     * `days` is populated, and vice versa in month view.
     *
     * @return array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: Collection<string, Collection<int, Milestone>>}
     */
    #[Computed]
    public function calendarGrid(): array
    {
        if ($this->viewMode === 'month') {
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

            $days = null;
        } else {
            $days = $this->periodDays();
            $month = $days[0];
            $gridStart = $days[0];
            $gridEnd = end($days);
            $weeks = null;
        }

        $milestonesByDay = $this->projectsWithMilestones
            ->flatMap(fn (Project $projectRow): Collection => $this->flattenMilestones($projectRow->milestones)->map(function (Milestone $milestone) use ($projectRow): Milestone {
                $milestone->setRelation('project', $projectRow);

                return $milestone;
            }))
            ->filter(fn (Milestone $milestone): bool => $milestone->planned_start_date->between($gridStart, $gridEnd))
            ->sortBy('sequence')
            ->groupBy(fn (Milestone $milestone): string => $milestone->planned_start_date->toDateString());

        return [
            'month' => $month,
            'weeks' => $weeks,
            'days' => $days,
            'milestonesByDay' => $milestonesByDay,
        ];
    }


    /**
     * The shared header selects bind these props directly, so route each
     * update through the filterBy* action that normalises it.
     */
    public function updatedProject(): void
    {
        $this->filterByProject($this->project === '' ? null : (int) $this->project);
    }

    public function updatedStatus(): void
    {
        $this->filterByStatus($this->status === '' ? null : $this->status);
    }

    public function updatedResponsible(): void
    {
        $this->filterByResponsible($this->responsible === '' ? null : (int) $this->responsible);
    }
    public function filterByProject(?int $projectId = null): void
    {
        $this->project = $projectId === null ? '' : (string) $projectId;
    }

    public function filterByStatus(?string $status = null): void
    {
        $this->status = $status !== null && MilestoneStatus::tryFrom($status) !== null ? $status : '';
    }

    public function filterByResponsible(?int $responsibleId = null): void
    {
        $this->responsible = $responsibleId === null ? '' : (string) $responsibleId;
    }

    public function updatedMilestoneProjectId(): void
    {
        $this->parentMilestoneId = '';

        unset($this->parentOptions);
    }

    /**
     * Projects carrying matching milestones, with ancestors retained so every
     * result remains understandable in its hierarchy.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectsWithMilestones(): Collection
    {
        $projects = Project::query()
            ->select(['id', 'name', 'city_id'])
            ->when($this->project !== '', fn (Builder $query) => $query->whereKey((int) $this->project))
            ->with([
                'city:id,name_en,name_ar',
                'milestones' => fn ($query) => $query
                    ->select([
                        'id',
                        'project_id',
                        'name',
                        'description',
                        'planned_start_date',
                        'planned_end_date',
                        'actual_start_date',
                        'actual_end_date',
                        'status',
                        'sequence',
                        'weight_percentage',
                        'responsible_user_id',
                        'parent_milestone_id',
                    ])
                    ->with(['responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
                    ->withCount(['children', 'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)])
                    ->orderBy('sequence')
                    ->orderBy('id'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $projects
            ->map(function (Project $project): Project {
                $childrenByParent = $project->milestones->groupBy(
                    fn (Milestone $milestone): string => $milestone->parent_milestone_id === null
                        ? 'root'
                        : (string) $milestone->parent_milestone_id,
                );

                $buildVisibleBranch = function (?int $parentId) use (&$buildVisibleBranch, $childrenByParent): Collection {
                    $key = $parentId === null ? 'root' : (string) $parentId;

                    /** @var Collection<int, Milestone> $children */
                    $children = $childrenByParent->get($key, new Collection);

                    return $children
                        ->map(function (Milestone $milestone) use (&$buildVisibleBranch): Milestone {
                            $milestone->setRelation('childrenRecursive', $buildVisibleBranch($milestone->id));

                            return $milestone;
                        })
                        ->filter(fn (Milestone $milestone): bool => $this->matchesMilestoneFilters($milestone)
                            || $milestone->childrenRecursive->isNotEmpty())
                        ->values();
                };

                $project->setRelation('milestones', $buildVisibleBranch(null));

                return $project;
            })
            ->filter(fn (Project $project): bool => $project->milestones->isNotEmpty())
            ->values();
    }

    /**
     * Delivery health across the currently filtered milestones.
     *
     * 'missed' is derived (Milestone::isMissed()), not a stored status — see
     * app/Enums/MilestoneStatus.php.
     *
     * @return array{total: int, reached: int, missed: int, due_soon: int}
     */
    #[Computed]
    public function milestoneStats(): array
    {
        $today = CarbonImmutable::today();

        $milestones = $this->projectsWithMilestones
            ->flatMap(fn (Project $project): Collection => $this->flattenMilestones($project->milestones));

        return [
            'total' => $milestones->count(),
            'reached' => $milestones->where('status', MilestoneStatus::Reached)->count(),
            'missed' => $milestones->filter(fn (Milestone $milestone): bool => $milestone->isMissed())->count(),
            'due_soon' => $milestones->filter(
                fn (Milestone $milestone): bool => $milestone->status === MilestoneStatus::Planned
                    && $milestone->planned_end_date !== null
                    && $milestone->planned_end_date->between($today, $today->addDays(7)),
            )->count(),
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

        unset($this->parentOptions);

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

        Milestone::query()->create([
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
        $deleted = DB::transaction(function () use ($milestoneId): bool {
            $milestone = Milestone::query()->lockForUpdate()->find($milestoneId);

            if ($milestone === null || $milestone->children()->exists() || $milestone->tasks()->withoutGlobalScope(TaskVisibilityScope::class)->exists()) {
                return false;
            }

            Gate::authorize('delete', $milestone);

            return (bool) $milestone->delete();
        });

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
        unset($this->parentOptions);
    }

    /**
     * Flattens a filtered milestone tree (as returned by projectsWithMilestones)
     * into a single ordered list for the timeline and board views.
     *
     * @param  Collection<int, Milestone>  $milestones
     * @return Collection<int, Milestone>
     */
    protected function flattenMilestones(Collection $milestones): Collection
    {
        return $milestones->reduce(
            fn (Collection $flat, Milestone $milestone): Collection => $flat
                ->push($milestone)
                ->merge($this->flattenMilestones($milestone->childrenRecursive)),
            new Collection,
        );
    }

    protected function matchesMilestoneFilters(Milestone $milestone): bool
    {
        return ($this->status === '' || $milestone->status->value === $this->status)
            && ($this->responsible === '' || $milestone->responsible_user_id === (int) $this->responsible);
    }

};
?>

@php
    $filtersAreActive = $project !== '' || $status !== '' || $responsible !== '';
    $totalMilestones = $this->projectsWithMilestones->sum(fn ($projectRow) => $this->flattenMilestones($projectRow->milestones)->count());
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="milestone-directory-heading">
    <x-ui.page-header heading-id="milestone-directory-heading" :title="__('common.milestones')" :count="$totalMilestones">
        <x-slot:filters>
            <x-ui.header-filter-select id="milestone-project-filter" :label="__('common.project')" wire:model.live="project" :value="$project">
                <flux:select.option value="">{{ __('common.all_projects') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'milestone-project-filter-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="milestone-status-filter" :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (MilestoneStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'milestone-status-filter-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="milestone-responsible-filter" :label="__('milestones.responsible')" wire:model.live="responsible" :value="$responsible">
                <flux:select.option value="">{{ __('common.anyone') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'milestone-user-filter-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('create', App\Models\Milestone::class)<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-milestone-button">{{ __('milestones.add_milestone') }}</x-ui.button>@endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-3 @2xl/directory:grid-cols-2 @5xl/directory:grid-cols-4">
        <x-ui.stat-tile :label="__('milestones.total_milestones')" :value="$this->milestoneStats['total']" icon="flag" wire:key="milestone-stat-total" />
        <x-ui.stat-tile :label="MilestoneStatus::Reached->label()" :value="$this->milestoneStats['reached']" icon="check-circle" tone="good" wire:key="milestone-stat-reached" />
        <x-ui.stat-tile :label="__('milestones.missed')" :value="$this->milestoneStats['missed']" icon="x-circle" tone="critical" wire:key="milestone-stat-missed" />
        <x-ui.stat-tile :label="__('milestones.due_within_7_days')" :value="$this->milestoneStats['due_soon']" icon="clock" wire:key="milestone-stat-due-soon" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-line dark:bg-surface-2" role="group" aria-label="{{ __('milestones.view') }}">
            @foreach ([
                'list' => ['icon' => 'queue-list', 'label' => __('milestones.list_view')],
                'timeline' => ['icon' => 'chart-bar', 'label' => __('milestones.timeline_view')],
                'board' => ['icon' => 'view-columns', 'label' => __('milestones.board_view')],
                'calendar' => ['icon' => 'calendar-days', 'label' => __('milestones.calendar_view')],
            ] as $viewOption => $viewMeta)
                <button
                    type="button"
                    wire:click="$set('view', '{{ $viewOption }}')"
                    aria-pressed="{{ $view === $viewOption ? 'true' : 'false' }}"
                    data-test="milestone-view-{{ $viewOption }}"
                    @class([
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:shadow-raised dark:text-ink' => $view === $viewOption,
                        'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $view !== $viewOption,
                    ])
                >
                    <flux:icon :name="$viewMeta['icon']" variant="micro" class="size-4" aria-hidden="true" />
                    {{ $viewMeta['label'] }}
                </button>
            @endforeach
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($this->projectsWithMilestones->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state icon="flag" :heading="$filtersAreActive ? __('milestones.no_matching_milestones') : __('common.no_milestones_yet')" :body="$filtersAreActive ? __('common.change_the_filters_and_try_again') : __('milestones.add_the_first_milestone_to_a_project')">
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        @can('create', App\Models\Milestone::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('milestones.add_milestone') }}</x-ui.button>@endcan
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        @switch ($view)
            @case ('timeline')
                @include('pages.milestones._timeline', ['projects' => $this->projectsWithMilestones])
                @break

            @case ('board')
                @include('pages.milestones._board', ['projects' => $this->projectsWithMilestones])
                @break

            @case ('calendar')
                @include('pages.milestones._calendar')
                @break

            @default
                @include('pages.milestones._list', ['projects' => $this->projectsWithMilestones])
        @endswitch
    @endif

    <x-ui.modal
        wire:model="showMilestoneModal"
        size="xl"
        :title="__('milestones.add_milestone')"
        submit="saveMilestone"
        close="closeMilestoneModal"
        required-note
    >
        <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'description']">
            <div class="grid gap-y-3">
                <x-ui.form-input wire:model="name" :label="__('milestones.milestone_name')" maxlength="255" required autofocus />
                <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_placement')" :fields="['milestoneProjectId', 'parentMilestoneId', 'responsibleUserId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                <x-ui.form-select wire:model.live="milestoneProjectId" id="milestone-form-project" :label="__('common.project')" :placeholder="__('common.select_a_project')" :search-label="__('milestones.search_projects')" required>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="parentMilestoneId" :label="__('milestones.nest_under')" :search-label="__('milestones.search_milestones')">
                    <flux:select.option value="">{{ __('common.top_level') }}</flux:select.option>
                    @foreach ($this->parentOptions as $parentOption)
                        <flux:select.option :value="$parentOption->id">{{ $parentOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="responsibleUserId" :label="__('milestones.responsible')" :placeholder="__('common.select')" :search-label="__('milestones.search_responsible')">
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedStartDate', 'plannedEndDate', 'milestoneStatus', 'weightPercentage']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                <x-ui.form-input wire:model="plannedStartDate" :label="__('milestones.planned_start_date')" type="date" required />
                <x-ui.form-input wire:model="plannedEndDate" :label="__('milestones.planned_end_date')" type="date" />

                <x-ui.form-select wire:model="milestoneStatus" :label="__('common.status')" required>
                    @foreach (MilestoneStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-input wire:model="weightPercentage" :label="__('milestones.weight')" type="number" min="0" max="100" step="0.01" suffix="%" required />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-milestone-button">{{ __('milestones.create_milestone') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('milestones.delete_this_milestone')"
        :message="$this->deletingMilestone !== null
            ? __('milestones.are_you_sure_you_want_to_delete_this_milestone', ['name' => $this->deletingMilestone->name])
            : __('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can')"
        confirm="deleteMilestone"
        :confirm-label="__('milestones.delete_milestone')"
        confirm-test="confirm-delete-milestone"
    />
</section>
