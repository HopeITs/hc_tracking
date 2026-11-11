<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ManagesTaskVisibility;
use App\Livewire\Concerns\NavigatesCalendarPeriods;
use App\Livewire\Concerns\Tasks\ComposesInlineTasks;
use App\Livewire\Concerns\Tasks\FiltersTaskDirectory;
use App\Livewire\Concerns\Tasks\ManagesTaskDrawer;
use App\Livewire\Concerns\Tasks\ProvidesTaskOptions;
use App\Livewire\Concerns\Tasks\ReadsTaskViews;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.tasks')] class extends Component
{
    use ComposesInlineTasks;
    use FiltersTaskDirectory;
    use ManagesTaskDrawer;
    use ManagesTaskVisibility, WithPagination;
    use NavigatesCalendarPeriods;
    use ProvidesTaskOptions;
    use ReadsTaskViews;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $assignee = '';

    /**
     * Filters tasks down to one source type, and (once a type is chosen)
     * one specific record of that type — the filter bar's own pair,
     * independent of the drawer's taskSourceType/taskSourceId below.
     */
    #[Url(except: '')]
    public string $sourceType = '';

    #[Url(except: '')]
    public string $sourceId = '';

    #[Url(as: 'view', except: 'board')]
    public string $view = 'board';

    /**
     * Only meaningful for a bypass role (see ManagesTaskVisibility) — a
     * department-scoped role never sees this filter at all, since their
     * scope is fixed to their own department.
     */
    #[Url(except: '')]
    public string $departmentId = '';

    /**
     * Assigned to, or created by, the viewer — always available regardless
     * of department scope, since it's inherently already about "me".
     */
    #[Url(except: false)]
    public bool $myTasksOnly = false;

    /** The tab above the views (FiltersTaskDirectory::setScope). */
    #[Url(except: '')]
    public string $scope = '';

    /**
     * due_today, due_soon, overdue or missed — set from the Due picker or by
     * pressing a headline figure (toggleDueFilter).
     */
    #[Url(except: '')]
    public string $dueFilter = '';

    #[Url(as: 'sort', except: 'due')]
    public string $sortField = 'due';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(except: 'none')]
    public string $group = 'none';

    /**
     * Which list layout this screen needs, reported by the browser from the
     * list's width ('wide' table or 'narrow' cards) and remembered for the
     * session. null until the first report: both layouts are rendered then.
     */
    #[Session]
    public ?string $listLayout = null;

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $anchorDate = '';

    #[Url(as: 'period', except: 'month')]
    public string $viewMode = 'month';
};
?>

@php
    $filtersAreActive = $this->hasActiveFilters();
    $advancedFilterCount = $this->activeAdvancedFilterCount();
    $canPickDepartment = auth()->user()->canViewAllTaskDepartments();

    /*
     * The filters kept behind the Filters button, shown as removable chips
     * under the bar while set. Status and Due stay visible in the bar, so
     * they need no chip.
     */
    $hiddenFilterChips = array_filter([
        'priority' => $priority !== '' ? TaskPriority::tryFrom($priority)?->label() : null,
        'assignee' => $assignee !== '' ? (App\Models\HrEmployee::find((int) $assignee)?->name ?? '#'.$assignee) : null,
        'departmentId' => $departmentId !== '' && $canPickDepartment ? collect($this->departmentOptions)->firstWhere('id', (int) $departmentId)['name'] ?? '#'.$departmentId : null,
        'sourceType' => $sourceType !== '' ? collect($this->sourceTypeOptions)->firstWhere('value', $sourceType)['label'] ?? $sourceType : null,
        'sourceId' => $sourceType !== '' && $sourceId !== '' ? collect($this->filterSourceOptions)->firstWhere('id', (int) $sourceId)['name'] ?? '#'.$sourceId : null,
        'myTasksOnly' => $myTasksOnly && $view !== 'cards' ? __('tasks.my_tasks_only_hint') : null,
    ], fn (?string $label): bool => $label !== null);
    $hiddenFilterLabels = [
        'priority' => __('tasks.priority'),
        'assignee' => __('tasks.assignee'),
        'departmentId' => __('tasks.department'),
        'sourceType' => __('tasks.source_type'),
        'sourceId' => __('tasks.source'),
        'myTasksOnly' => __('tasks.my_tasks_only'),
    ];

    /** Headline figures: each one filters the list, and a second press clears it. */
    $dueFigures = [
        'overdue' => ['label' => __('tasks.overdue'), 'hint' => __('tasks.overdue_hint'), 'tone' => 'dk-bad'],
        'missed' => ['label' => __('tasks.missed'), 'hint' => __('tasks.missed_hint'), 'tone' => 'dk-bad'],
        'due_soon' => ['label' => __('tasks.due_within_7_days'), 'hint' => null, 'tone' => 'dk-warn'],
    ];

@endphp

<x-desk.register
    class="admin-directory dk-tasks-reg @container/directory"
    heading-id="task-directory-heading"
    :title="__('common.tasks')"
    :count="$this->taskStats['total']"
    :view="$view"
    :views="[
        'list' => ['icon' => 'table', 'label' => __('desk.register.table'), 'test' => 'task-view-list'],
        'board' => ['icon' => 'board', 'label' => __('desk.register.board'), 'test' => 'task-view-board'],
        'calendar' => ['icon' => 'cal', 'label' => __('desk.register.calendar'), 'test' => 'task-view-calendar'],
        'cards' => ['icon' => 'grid', 'label' => __('desk.register.cards'), 'test' => 'task-view-cards'],
    ]"
>
    <x-slot:figures>
        {{-- Done is said against its denominator; the three "needs a hand" figures each filter the list. --}}
        <span class="dk-tk-fig-done" data-test="task-stat-done"><b>{{ $this->taskStats['done'] }}</b>{{ __('tasks.done_of_total', ['total' => $this->taskStats['all']]) }}</span>
        @foreach ($dueFigures as $figureKey => $figure)
            <button
                type="button"
                wire:click="toggleDueFilter('{{ $figureKey }}')"
                aria-pressed="{{ $dueFilter === $figureKey ? 'true' : 'false' }}"
                aria-label="{{ __('tasks.filter_by_figure', ['label' => $figure['label']]) }}: {{ $this->taskStats[$figureKey] }}"
                @if ($figure['hint']) title="{{ $figure['hint'] }}" @endif
                @class(['dk-fig-btn', $figure['tone'] => $this->taskStats[$figureKey] > 0, 'dk-tk-fig-zero' => $this->taskStats[$figureKey] === 0])
                data-test="task-stat-{{ str_replace('_', '-', $figureKey) }}"
            ><b>{{ $this->taskStats[$figureKey] }}</b>{{ $figure['label'] }}</button>
        @endforeach
    </x-slot:figures>
    <x-slot:actions>
        @can('create', App\Models\Task::class)
            <button type="button" class="dk-btn dk-primary" wire:click="openCreateDrawer" data-test="create-task-button" data-quick-create><x-desk.icon name="plus" />{{ __('tasks.add_task') }}</button>
        @endcan
    </x-slot:actions>

    @include('pages.tasks._index-filters')

    @if ($this->taskStats['total'] === 0)
        @php
            $emptyIsScoped = ! $filtersAreActive && $scope !== '';
            $emptyHeading = match (true) {
                $filtersAreActive => __('tasks.no_matching_tasks'),
                $emptyIsScoped => __('tasks.empty_scope_heading'),
                $view === 'cards' => __('tasks.nothing_open_for_you'),
                default => __('common.no_tasks_yet'),
            };
            $emptyBody = match (true) {
                $filtersAreActive => __('common.change_the_search_or_filters_and_try_again'),
                $emptyIsScoped => __('tasks.empty_scope_body'),
                default => __('tasks.add_the_first_task_against_a_proposal'),
            };
        @endphp
        <x-ui.panel>
            <x-ui.empty-state icon="clipboard-document-check" :heading="$emptyHeading" :body="$emptyBody">
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @elseif ($emptyIsScoped)
                        <x-ui.button size="sm" wire:click="setScope('')" data-test="task-empty-show-all">{{ __('tasks.show_all_tasks') }}</x-ui.button>
                    @elseif (auth()->user()->can('create', App\Models\Task::class))
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateDrawer">{{ __('tasks.add_task') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        @switch ($view)
            @case ('board')
                @include('pages.tasks._board')
                @break

            @case ('calendar')
                @include('pages.tasks._calendar')
                @break

            @case ('cards')
                @include('pages.tasks._cards')
                @break

            @default
                @include('pages.tasks._list')
        @endswitch

        @include('pages.tasks._assignee-popover')
        @include('pages.tasks._task-option-menu')
        @include('pages.tasks._person-card')
    @endif

    @include('pages.tasks._index-drawer')

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('tasks.delete_this_task')"
        :message="$this->deletingTask !== null
            ? __('tasks.are_you_sure_you_want_to_delete_this_task', ['title' => $this->deletingTask->title])
            : __('tasks.its_attachment_records_are_removed_with_it_nothing_outside')"
        confirm="deleteTask"
        :confirm-label="__('tasks.delete_task')"
        confirm-test="confirm-delete-task"
    />
</x-desk.register>
