{{--
    Everything between the register header and the task views, in reading
    order: the scope tabs (whose tasks), one toolbar (search, Status, Due,
    Filters, Clear, then Display at the end), the chips for any filter set
    behind the Filters button, and the view-only notice. The view switch and
    Add task stay in the x-desk.register header. Included by ⚡index.blade.php,
    whose top PHP block defines $filtersAreActive, $advancedFilterCount,
    $canPickDepartment, $hiddenFilterChips and $hiddenFilterLabels.
--}}
<nav class="dk-tk-tabs" aria-label="{{ __('tasks.tabs_label') }}" data-test="task-scope-tabs">
    @foreach (['' => __('tasks.tab_all'), 'mine' => __('tasks.tab_mine'), 'assigned' => __('tasks.tab_assigned'), 'review' => __('tasks.tab_review')] as $scopeKey => $scopeLabel)
        <button
            type="button"
            wire:click="setScope('{{ $scopeKey }}')"
            aria-current="{{ $scope === $scopeKey ? 'page' : 'false' }}"
            data-test="task-scope-{{ $scopeKey === '' ? 'all' : $scopeKey }}"
        >
            {{ $scopeLabel }}
            @if ($scopeKey === 'review' && $this->tasksAwaitingMyReview > 0)
                <span class="dk-num dk-tk-tab-count" data-test="task-scope-review-count">{{ $this->tasksAwaitingMyReview }}</span>
            @endif
        </button>
    @endforeach
</nav>

<div class="dk-reg-bar dk-tk-bar" data-desk-register-bar>
    <x-desk.register-search :label="__('tasks.search_tasks')" :placeholder="__('tasks.title_description_or_assignee')" wire:model.live.debounce.300ms="search" />

    <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
        <option value="">{{ __('common.all_statuses') }}</option>
        @foreach (App\Enums\TaskStatus::cases() as $statusCase)
            <option value="{{ $statusCase->value }}">{{ $statusCase->label() }}</option>
        @endforeach
    </x-ui.header-filter-select>
    <x-ui.header-filter-select :label="__('desk.register.due')" wire:model.live="dueFilter" :value="$dueFilter" data-test="task-due-filter">
        <option value="">{{ __('desk.register.due_any') }}</option>
        <option value="due_today">{{ __('tasks.due_today') }}</option>
        <option value="due_soon">{{ __('tasks.due_within_7_days') }}</option>
        <option value="overdue">{{ __('tasks.overdue') }}</option>
        <option value="missed">{{ __('tasks.missed_not_started') }}</option>
    </x-ui.header-filter-select>

    {{-- Less frequent filters sit behind one button; what is set shows as chips under the bar. --}}
    <div class="dk-pmenu dk-tkf" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <button type="button" @class(['dk-btn', 'dk-tkf-btn', 'dk-tkf-on' => $advancedFilterCount > 0]) x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="dialog" aria-label="{{ __('tasks.filters') }}" data-test="task-filters-button">
            <flux:icon.adjustments-horizontal variant="micro" class="size-4" aria-hidden="true" /><span class="dk-tk-btn-text">{{ __('tasks.filters') }}</span>
            @if ($advancedFilterCount > 0)
                <span class="dk-pc dk-num" data-test="task-filters-count">{{ $advancedFilterCount }}</span>
            @endif
        </button>

        <div class="dk-tkf-scrim" x-show="open" x-cloak x-on:click="open = false" aria-hidden="true"></div>
        <div class="dk-pop dk-pjf-pop dk-tkf-pop" role="dialog" aria-label="{{ __('tasks.filters') }}" x-show="open" x-cloak x-transition.opacity.duration.120ms>
            <div class="dk-tkf-sec" role="group" aria-labelledby="task-filters-work">
                <p class="dk-tkf-h" id="task-filters-work">{{ __('tasks.filters_work') }}</p>
                <x-ui.header-filter-select :label="__('tasks.priority')" wire:model.live="priority" :value="$priority">
                    <option value="">{{ __('tasks.all_priorities') }}</option>
                    @foreach (App\Enums\TaskPriority::cases() as $priorityCase)
                        <option value="{{ $priorityCase->value }}">{{ $priorityCase->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            </div>
            <div class="dk-tkf-sec" role="group" aria-labelledby="task-filters-people">
                <p class="dk-tkf-h" id="task-filters-people">{{ __('tasks.filters_people') }}</p>
                <x-ui.header-filter-select :label="__('tasks.assignee')" wire:model.live="assignee" :value="$assignee" employees>
                    <option value="">{{ __('common.anyone') }}</option>
                </x-ui.header-filter-select>
                @if ($canPickDepartment)
                    <x-ui.header-filter-select :label="__('tasks.department')" wire:model.live="departmentId" :value="$departmentId" data-test="task-department-filter">
                        <option value="">{{ __('tasks.every_department') }}</option>
                        @foreach ($this->departmentOptions as $departmentOption)
                            <option value="{{ $departmentOption['id'] }}">{{ $departmentOption['name'] }}</option>
                        @endforeach
                    </x-ui.header-filter-select>
                @endif
                @if ($view !== 'cards')
                    {{-- Cards always shows only the viewer's own tasks, so the switch would do nothing there. --}}
                    <label class="dk-tkf-check">
                        <input type="checkbox" wire:click="toggleMyTasksOnly" @checked($myTasksOnly) data-test="task-filter-my-tasks-only" />
                        <span><b>{{ __('tasks.my_tasks_only') }}</b><small>{{ __('tasks.my_tasks_only_hint') }}</small></span>
                    </label>
                @endif
            </div>
            <div class="dk-tkf-sec dk-tkf-sec-related" role="group" aria-labelledby="task-filters-related">
                <p class="dk-tkf-h" id="task-filters-related">{{ __('tasks.filters_related_to') }}</p>
                <x-ui.header-filter-select :label="__('tasks.source_type')" wire:model.live="sourceType" :value="$sourceType">
                    <option value="">{{ __('tasks.related_to_any') }}</option>
                    @foreach ($this->sourceTypeOptions as $sourceTypeOption)
                        <option value="{{ $sourceTypeOption['value'] }}">{{ $sourceTypeOption['label'] }}</option>
                    @endforeach
                </x-ui.header-filter-select>
                @if ($sourceType !== '')
                    <x-ui.header-filter-select :label="__('tasks.source')" wire:model.live="sourceId" :value="$sourceId">
                        <option value="">{{ __('tasks.any_record') }}</option>
                        @foreach ($this->filterSourceOptions as $sourceOption)
                            <option value="{{ $sourceOption['id'] }}">{{ $sourceOption['name'] }}</option>
                        @endforeach
                    </x-ui.header-filter-select>
                @endif
            </div>
            {{-- Wrapped: desk.css styles every direct `.dk-pop > button` as a menu row. --}}
            <div class="dk-tkf-foot">
                @if ($advancedFilterCount > 0)
                    <button type="button" class="dk-btn dk-ghost" wire:click="clearAdvancedFilters" data-test="task-filters-clear">{{ __('desk.register.clear') }}</button>
                @endif
                <button type="button" class="dk-btn" x-on:click="open = false">{{ __('desk.filter_done') }}</button>
            </div>
        </div>
    </div>

    @if ($filtersAreActive)
        <button type="button" class="dk-btn dk-ghost dk-tk-clear" wire:click="clearFilters" data-test="task-clear-filters">{{ __('tasks.clear_filters') }}</button>
    @endif

    @if ($view === 'list' || $view === 'board')
        <div class="dk-tk-end">
            @if ($view === 'list')
                {{-- How the list is laid out (group, sort): one Display button, not filters. --}}
                <div class="dk-pmenu dk-tkf dk-tkd" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                    <button type="button" class="dk-btn dk-tkf-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="dialog" aria-label="{{ __('tasks.display_options') }}" data-test="task-display-button">
                        <flux:icon.bars-3-bottom-left variant="micro" class="size-4" aria-hidden="true" /><span class="dk-tk-btn-text">{{ __('tasks.display_options') }}</span>
                    </button>

                    <div class="dk-tkf-scrim" x-show="open" x-cloak x-on:click="open = false" aria-hidden="true"></div>
                    <div class="dk-pop dk-pjf-pop dk-tkf-pop" role="dialog" aria-label="{{ __('tasks.display_options') }}" x-show="open" x-cloak x-transition.opacity.duration.120ms>
                        <div class="dk-tkf-sec" role="group" aria-label="{{ __('tasks.task_toolbar') }}">
                            <x-ui.header-filter-select :label="__('tasks.group_by')" wire:model.live="group" :value="$group" data-test="task-group-select">
                                <option value="none">{{ __('tasks.no_grouping') }}</option>
                                <option value="status">{{ __('common.status') }}</option>
                                <option value="priority">{{ __('tasks.priority') }}</option>
                                <option value="assignee">{{ __('tasks.assignee') }}</option>
                                <option value="milestone">{{ __('tasks.milestone') }}</option>
                            </x-ui.header-filter-select>
                            <div class="dk-tk-sort">
                                <x-ui.header-filter-select :label="__('tasks.sort_by')" wire:model.live="sortField" :value="$sortField" data-test="task-sort-select">
                                    <option value="due">{{ __('tasks.due_date_2') }}</option>
                                    <option value="title">{{ __('common.name') }}</option>
                                    <option value="status">{{ __('common.status') }}</option>
                                    <option value="priority">{{ __('tasks.priority') }}</option>
                                    <option value="assignee">{{ __('tasks.assignee') }}</option>
                                    <option value="created">{{ __('tasks.created_date') }}</option>
                                </x-ui.header-filter-select>
                                <button
                                    type="button"
                                    class="dk-btn dk-tk-icon-btn"
                                    wire:click="$set('sortDirection', '{{ $sortDirection === 'asc' ? 'desc' : 'asc' }}')"
                                    aria-label="{{ $sortDirection === 'asc' ? __('tasks.sort_ascending') : __('tasks.sort_descending') }}"
                                    title="{{ $sortDirection === 'asc' ? __('tasks.sort_ascending') : __('tasks.sort_descending') }}"
                                    data-test="task-sort-direction-toggle"
                                >
                                    <flux:icon :name="$sortDirection === 'asc' ? 'bars-arrow-up' : 'bars-arrow-down'" variant="micro" class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                        <div class="dk-tkf-foot">
                            <button type="button" class="dk-btn" x-on:click="open = false">{{ __('desk.filter_done') }}</button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Opens or closes every task's subtasks at once; remembered per browser (task-subtasks.js). --}}
            <button
                type="button"
                x-data
                x-on:click="$store.taskSubtasks.toggleAll()"
                x-bind:aria-pressed="$store.taskSubtasks.allOpen ? 'true' : 'false'"
                class="dk-btn dk-tk-subtasks"
                data-test="task-subtasks-toggle-all"
            >
                <flux:icon.rectangle-stack variant="micro" class="size-4" aria-hidden="true" />
                <span class="dk-tk-btn-text" x-text="$store.taskSubtasks.allOpen ? {{ Illuminate\Support\Js::from(__('tasks.hide_subtasks')) }} : {{ Illuminate\Support\Js::from(__('tasks.show_subtasks')) }}">{{ __('tasks.show_subtasks') }}</span>
            </button>
        </div>
    @endif
</div>

@if ($hiddenFilterChips !== [])
    <div class="dk-pjq-row dk-tk-chips" data-test="task-active-filter-chips">
        @foreach ($hiddenFilterChips as $filterKey => $filterValue)
            <button
                type="button"
                class="dk-chip dk-pjq-set"
                @if ($filterKey === 'myTasksOnly') wire:click="toggleMyTasksOnly" @else wire:click="$set('{{ $filterKey }}', '')" @endif
                wire:key="task-active-filter-{{ $filterKey }}"
                aria-label="{{ __('tasks.remove_filter', ['filter' => $hiddenFilterLabels[$filterKey].': '.$filterValue]) }}"
                data-test="task-active-filter-{{ $filterKey }}"
            >
                <span class="dk-pjq-k">{{ $hiddenFilterLabels[$filterKey] }}</span> <bdi>{{ $filterValue }}</bdi> <x-desk.icon name="x" />
            </button>
        @endforeach
    </div>
@endif

@if (! auth()->user()->can('tasks.update') && ! auth()->user()->can('create', App\Models\Task::class))
    <p class="dk-tk-notice" data-test="task-view-only-notice">
        <flux:icon.eye variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
        {{ __('tasks.view_only_notice') }}
    </p>
@endif
