<?php

use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\Milestones\ManagesMilestoneDirectoryForm;
use App\Livewire\Concerns\NavigatesCalendarPeriods;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Scopes\TaskVisibilityScope;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.milestones')] class extends Component {
    use ManagesMilestoneDirectoryForm;
    use NavigatesCalendarPeriods;

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

    public function clearFilters(): void
    {
        $this->project = '';
        $this->status = '';
        $this->responsible = '';
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
            ->flatMap(fn (Project|Proposal $projectRow): Collection => $this->flattenMilestones($projectRow->milestones)->map(function (Milestone $milestone) use ($projectRow): Milestone {
                $milestone->setRelation($projectRow instanceof Project ? 'project' : 'proposal', $projectRow);

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

    /**
     * Projects carrying matching milestones, then proposals carrying matching
     * proposal-level milestones (no project, contracts.md §12.2), with
     * ancestors retained so every result remains understandable in its
     * hierarchy. Proposal groups are left out while a project is filtered.
     *
     * @return Collection<int, Project|Proposal>
     */
    #[Computed]
    public function projectsWithMilestones(): Collection
    {
        $milestoneColumns = [
            'id',
            'project_id',
            'proposal_id',
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
        ];

        $loadMilestones = fn ($query) => $query
            ->select($milestoneColumns)
            ->with(['responsibleUser:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount(['children', 'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)])
            ->orderBy('sequence')
            ->orderBy('id');

        $projects = Project::query()
            ->select(['id', 'name', 'city_id'])
            ->when($this->project !== '', fn (Builder $query) => $query->whereKey((int) $this->project))
            ->with([
                'city:id,name_en,name_ar',
                'milestones' => $loadMilestones,
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $proposals = $this->project !== '' ? new Collection : Proposal::query()
            ->select(['id', 'name', 'slug'])
            ->whereHas('milestones', fn (Builder $query) => $query->whereNull('project_id'))
            ->with(['milestones' => fn ($query) => $loadMilestones($query->whereNull('project_id'))])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $projects
            ->concat($proposals)
            ->map(function (Project|Proposal $owner): Project|Proposal {
                $childrenByParent = $owner->milestones->groupBy(
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

                $owner->setRelation('milestones', $buildVisibleBranch(null));

                return $owner;
            })
            ->filter(fn (Project|Proposal $owner): bool => $owner->milestones->isNotEmpty())
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
            ->flatMap(fn (Project|Proposal $owner): Collection => $this->flattenMilestones($owner->milestones));

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
    $milestoneViews = [
        'list' => ['icon' => 'table', 'label' => __('milestones.list_view'), 'test' => 'milestone-view-list'],
        'timeline' => ['icon' => 'chart', 'label' => __('milestones.timeline_view'), 'test' => 'milestone-view-timeline'],
        'board' => ['icon' => 'board', 'label' => __('milestones.board_view'), 'test' => 'milestone-view-board'],
        'calendar' => ['icon' => 'cal', 'label' => __('milestones.calendar_view'), 'test' => 'milestone-view-calendar'],
    ];
    $totalMilestones = $this->projectsWithMilestones->sum(fn ($projectRow) => $this->flattenMilestones($projectRow->milestones)->count());
@endphp

<x-desk.register
    class="admin-directory @container/directory"
    heading-id="milestone-directory-heading"
    :title="__('common.milestones')"
    :count="$totalMilestones"
    :view="$view"
    :views="$milestoneViews"
>
    <x-slot:figures>
        <span class="dk-good" wire:key="milestone-stat-reached"><b>{{ $this->milestoneStats['reached'] }}</b>{{ App\Enums\MilestoneStatus::Reached->label() }}</span>
        <span class="dk-bad" wire:key="milestone-stat-missed"><b>{{ $this->milestoneStats['missed'] }}</b>{{ __('milestones.missed') }}</span>
        <span class="dk-warn" wire:key="milestone-stat-due-soon"><b>{{ $this->milestoneStats['due_soon'] }}</b>{{ __('milestones.due_within_7_days') }}</span>
    </x-slot:figures>
    <x-slot:actions>
            @can('create', App\Models\Milestone::class)
                <button type="button" class="dk-btn dk-primary" wire:click="openCreateModal" data-test="create-milestone-button"><x-desk.icon name="plus" />{{ __('milestones.add_milestone') }}</button>
            @endcan
        </x-slot:actions>
    <x-slot:filters>
            <x-ui.header-filter-select id="milestone-project-filter" :label="__('common.project')" wire:model.live="project" :value="$project">
                <option value="">{{ __('common.all_projects') }}</option>
                @foreach ($this->projectOptions as $projectOption)
                    <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="milestone-status-filter" :label="__('common.status')" wire:model.live="status" :value="$status">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach (MilestoneStatus::cases() as $statusCase)
                    <option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select id="milestone-responsible-filter" :label="__('milestones.responsible')" wire:model.live="responsible" :value="$responsible" employees>
                <option value="">{{ __('common.anyone') }}</option>
            </x-ui.header-filter-select>

            @if ($filtersAreActive)
                <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="clearFilters"><x-desk.icon name="x" />{{ __('desk.register.clear') }}</button>
            @endif
        </x-slot:filters>


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

    @include('pages.milestones._index-milestone-modal')

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
</x-desk.register>
