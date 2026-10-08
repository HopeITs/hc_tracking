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
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.tasks')] class extends Component
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

    /**
     * Set by clicking the "Overdue" or "Due soon" stat tile — a quick filter
     * alongside status/priority/assignee rather than a fifth dropdown.
     */
    #[Url(except: '')]
    public string $dueFilter = '';

    #[Url(as: 'sort', except: 'due')]
    public string $sortField = 'due';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(except: 'none')]
    public string $group = 'none';

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $anchorDate = '';

    #[Url(as: 'period', except: 'month')]
    public string $viewMode = 'month';
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $priority !== '' || $assignee !== '' || $sourceType !== '' || $departmentId !== '' || $dueFilter !== '' || $myTasksOnly;
    $canPickDepartment = auth()->user()->canViewAllTaskDepartments();
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="task-directory-heading">
    <x-ui.page-header heading-id="task-directory-heading" :title="__('common.tasks')" :count="$this->taskStats['total']">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('tasks.search_tasks')" :placeholder="__('tasks.title_description_or_assignee')" wire:model.live.debounce.300ms="search" clearable />
            <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach (TaskStatus::cases() as $statusCase)
                    <option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>
            <x-ui.header-filter-select :label="__('tasks.priority')" wire:model.live="priority" :value="$priority">
                <option value="">{{ __('tasks.all_priorities') }}</option>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <option value="{{ $priorityCase->value }}">{{ $priorityCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>
            <x-ui.header-filter-select :label="__('tasks.assignee')" wire:model.live="assignee" :value="$assignee">
                <option value="">{{ __('common.anyone') }}</option>
                @foreach ($this->userOptions as $userOption)
                    <option value="{{ $userOption['id'] }}">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</option>
                @endforeach
            </x-ui.header-filter-select>
            @if ($canPickDepartment)
                <x-ui.header-filter-select :label="__('tasks.department')" wire:model.live="departmentId" :value="$departmentId" data-test="task-department-filter">
                    <option value="">{{ __('tasks.every_department') }}</option>
                    @foreach ($this->departmentOptions as $departmentOption)
                        <option value="{{ $departmentOption['id'] }}">{{ $departmentOption['name'] }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
            <x-ui.header-filter-select :label="__('tasks.source_type')" wire:model.live="sourceType" :value="$sourceType">
                <option value="">{{ __('common.select') }}</option>
                @foreach ($this->sourceTypeOptions as $sourceTypeOption)
                    <option value="{{ $sourceTypeOption['value'] }}">{{ $sourceTypeOption['label'] }}</option>
                @endforeach
            </x-ui.header-filter-select>
            @if ($sourceType !== '')
                <x-ui.header-filter-select :label="__('tasks.source')" wire:model.live="sourceId" :value="$sourceId">
                    <option value="">{{ __('common.select') }}</option>
                    @foreach ($this->filterSourceOptions as $sourceOption)
                        <option value="{{ $sourceOption['id'] }}">{{ $sourceOption['name'] }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
        </x-slot:filters>
        <x-slot:actions>
            @can('create', App\Models\Task::class)
                <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateDrawer" data-test="create-task-button">{{ __('tasks.add_task') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('pages.tasks._index-filters')

    @if ($this->taskStats['total'] === 0)
        <x-ui.panel>
            <x-ui.empty-state icon="clipboard-document-check" :heading="$filtersAreActive ? __('tasks.no_matching_tasks') : __('common.no_tasks_yet')" :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('tasks.add_the_first_task_against_a_proposal')">
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
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

            @default
                @include('pages.tasks._list')
        @endswitch

        @include('pages.tasks._assignee-popover')
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
</section>
