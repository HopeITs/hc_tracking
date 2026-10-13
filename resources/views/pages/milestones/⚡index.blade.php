<?php

use App\Enums\MilestoneStatus;
use App\Livewire\Concerns\Milestones\ManagesMilestoneDirectoryForm;
use App\Livewire\Concerns\NavigatesCalendarPeriods;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Scopes\TaskVisibilityScope;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.milestones')] class extends Component {
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
</section>
