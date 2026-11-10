@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Illuminate\Support\Js;

    /**
     * The tasks page's calendar view: one surface with the period toolbar,
     * the grid and the Unscheduled panel. Layout (two columns, the phone
     * agenda) is decided by container queries in desk-register.css (.dk-tc).
     *
     * @var array{month: \Carbon\CarbonImmutable, weeks: ?array<int, array<int, \Carbon\CarbonImmutable>>, days: ?array<int, \Carbon\CarbonImmutable>, tasksByDay: \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>>, unscheduled: \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task>, unscheduledTotal: int} $calendar
     */
    $calendar = $this->calendarGrid;
    $today = \Carbon\CarbonImmutable::today();

    $chipState = function (\App\Models\Task $task): string {
        if ($task->status === TaskStatus::Done) {
            return 'done';
        }

        return $task->isOverdue() || $task->isMissed() ? 'late' : 'open';
    };

    $markerLabel = function (\App\Models\Task $task): ?string {
        if ($task->isMissed()) {
            return __('tasks.missed');
        }

        if ($task->isOverdue()) {
            return __('tasks.overdue');
        }

        return null;
    };

    $priorityIconClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'text-primary dark:text-brand-text',
        TaskPriority::Medium => 'text-status-warn',
        TaskPriority::Low => 'text-secondary-light',
    };

    $periodHeading = match ($viewMode) {
        'month' => $calendar['month']->translatedFormat(__('formats.month_year')),
        'day' => $calendar['days'][0]->translatedFormat('l, j F Y'),
        default => $calendar['days'][0]->translatedFormat('j M').' – '.end($calendar['days'])->translatedFormat('j M Y'),
    };

    $cellPartial = ['calendar' => $calendar, 'chipState' => $chipState, 'markerLabel' => $markerLabel, 'priorityIconClasses' => $priorityIconClasses];

    // Only a task's creator can move its due date (RescheduleTask), so only
    // their cards get a drag handle, a Schedule action and the drag hint.
    $unscheduled = $calendar['unscheduled'];
    $canRescheduleAnyUnscheduled = $unscheduled->contains(fn ($task) => $task->created_by === auth()->id());
    $priorityRank = fn (TaskPriority $priority): int => match ($priority) {
        TaskPriority::High => 0,
        TaskPriority::Medium => 1,
        TaskPriority::Low => 2,
    };
    $byPriority = $unscheduled->sortBy([fn ($a, $b) => $priorityRank($a->priority) <=> $priorityRank($b->priority), fn ($a, $b) => $b->id <=> $a->id])->pluck('id')->flip();
    $byTitle = $unscheduled->sortBy(fn ($task) => mb_strtolower($task->title))->pluck('id')->flip();
@endphp

<div class="dk-tc-wrap">
    <div
        class="dk-tc"
        x-data="{
            sideOpen: (() => { try { return localStorage.getItem('tasks.calendar.unscheduled') !== 'closed' } catch (e) { return true } })(),
            toggleSide() {
                this.sideOpen = ! this.sideOpen;
                try { localStorage.setItem('tasks.calendar.unscheduled', this.sideOpen ? 'open' : 'closed') } catch (e) {}
            },
        }"
        x-bind:class="{ 'is-side-closed': ! sideOpen }"
        data-test="task-calendar"
    >
        <section class="dk-tc-main" aria-labelledby="task-calendar-period">
            <div class="dk-tc-bar">
                <div class="dk-tc-nav" role="group" aria-label="{{ __('tasks.calendar_period_navigation') }}">
                    <button type="button" class="dk-tc-btn" wire:click="goToToday" data-test="task-calendar-today-button">{{ __('common.today') }}</button>
                    <div class="dk-tc-arrows">
                        <button type="button" class="dk-tc-icon-btn" wire:click="{{ $viewMode === 'month' ? 'previousMonth' : 'previousPeriod' }}" aria-label="{{ __('common.previous') }}" data-test="task-calendar-previous">
                            <flux:icon.chevron-left variant="micro" class="size-4 rtl:-scale-x-100" />
                        </button>
                        <button type="button" class="dk-tc-icon-btn" wire:click="{{ $viewMode === 'month' ? 'nextMonth' : 'nextPeriod' }}" aria-label="{{ __('common.next') }}" data-test="task-calendar-next">
                            <flux:icon.chevron-right variant="micro" class="size-4 rtl:-scale-x-100" />
                        </button>
                    </div>
                    <h3 id="task-calendar-period" class="dk-tc-period" aria-live="polite">{{ $periodHeading }}</h3>
                    <span wire:loading.delay class="dk-tc-loading" aria-hidden="true"></span>
                </div>

                <div class="dk-tc-modes" role="group" aria-label="{{ __('calendar.time_period') }}" data-test="task-calendar-view-mode-button">
                    @foreach (['day', 'four_days', 'week', 'month'] as $periodKey)
                        <button
                            type="button"
                            wire:click="setViewMode('{{ $periodKey }}')"
                            aria-pressed="{{ $viewMode === $periodKey ? 'true' : 'false' }}"
                            data-test="task-calendar-view-mode-{{ $periodKey }}"
                        >{{ __('calendar.'.$periodKey) }}</button>
                    @endforeach
                </div>
            </div>

            @if ($viewMode === 'month')
                {{-- Wide: the seven-column grid with chips. --}}
                <div class="dk-tc-month" x-data="{ addOpen: {}, moreOpen: null }">
                    <div class="dk-tc-weekdays" aria-hidden="true">
                        @foreach ($calendar['weeks'][0] as $weekdayHeader)
                            <div>{{ $weekdayHeader->translatedFormat('D') }}</div>
                        @endforeach
                    </div>

                    <div class="dk-tc-days">
                        @foreach ($calendar['weeks'] as $week)
                            @foreach ($week as $weekdayIndex => $day)
                                @php
                                    $isCurrentMonth = $day->month === $calendar['month']->month;
                                    $isToday = $day->isSameDay($today);
                                @endphp
                                <div
                                    wire:key="task-calendar-day-{{ $day->toDateString() }}"
                                    @class(['dk-tc-cell', 'is-out' => ! $isCurrentMonth, 'is-today' => $isToday])
                                    data-test="task-calendar-day-{{ $day->toDateString() }}"
                                >
                                    <div class="dk-tc-cell-head">
                                        <span class="dk-tc-date" @if ($isToday) aria-current="date" @endif>
                                            <span class="sr-only">{{ $day->translatedFormat('l, j F') }}</span>
                                            <span aria-hidden="true">{{ $day->day === 1 && ! $isToday ? $day->translatedFormat('j M') : $day->day }}</span>
                                        </span>
                                    </div>

                                    @include('pages.tasks._calendar-day-cell', $cellPartial + ['day' => $day, 'chipLimit' => 3, 'alignEnd' => $weekdayIndex >= 5])
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>

                {{-- Narrow: a compact month of dots, and the picked day's tasks as a readable list. --}}
                @php
                    $agendaDefault = $today->isSameMonth($calendar['month']) ? $today->toDateString() : $calendar['month']->toDateString();
                    $agendaDays = collect($calendar['weeks'])->flatten(1);
                    $daysWithTasks = $agendaDays->filter(fn ($day) => $calendar['tasksByDay']->has($day->toDateString()));
                @endphp
                <div class="dk-tc-agenda" x-data="{ selected: {{ Js::from($agendaDefault) }} }" data-test="task-calendar-agenda">
                    <div class="dk-tc-mini-weekdays" aria-hidden="true">
                        @foreach ($calendar['weeks'][0] as $weekdayHeader)
                            <div>{{ mb_substr($weekdayHeader->translatedFormat('D'), 0, 2) }}</div>
                        @endforeach
                    </div>
                    <div class="dk-tc-mini">
                        @foreach ($agendaDays as $day)
                            @php
                                $dayKey = $day->toDateString();
                                $dayCount = $calendar['tasksByDay']->get($dayKey)?->count() ?? 0;
                            @endphp
                            <button
                                type="button"
                                wire:key="task-calendar-mini-{{ $dayKey }}"
                                @class(['dk-tc-mini-day', 'is-out' => $day->month !== $calendar['month']->month, 'is-today' => $day->isSameDay($today)])
                                x-on:click="selected = {{ Js::from($dayKey) }}"
                                x-bind:aria-pressed="selected === {{ Js::from($dayKey) }} ? 'true' : 'false'"
                                aria-label="{{ $day->translatedFormat('l, j F') }}{{ $dayCount > 0 ? ' · '.trans_choice('proposals.count_task', $dayCount, ['count' => $dayCount]) : '' }}"
                            >
                                <span>{{ $day->day }}</span>
                                <span class="dk-tc-mini-dots" aria-hidden="true">
                                    @foreach (($calendar['tasksByDay']->get($dayKey) ?? collect())->take(3) as $task)
                                        <i data-state="{{ $chipState($task) }}"></i>
                                    @endforeach
                                </span>
                            </button>
                        @endforeach
                    </div>

                    <div class="dk-tc-agenda-list" aria-live="polite">
                        @foreach ($daysWithTasks as $day)
                            @php $dayKey = $day->toDateString(); @endphp
                            <section x-show="selected === {{ Js::from($dayKey) }}" @if ($dayKey !== $agendaDefault) x-cloak @endif wire:key="task-calendar-agenda-{{ $dayKey }}">
                                <h4 class="dk-tc-agenda-day">{{ $day->translatedFormat('l, j F') }}</h4>
                                <ul class="dk-tc-agenda-items">
                                    @foreach ($calendar['tasksByDay']->get($dayKey) as $task)
                                        @php $chipLabel = $markerLabel($task); @endphp
                                        <li class="dk-tc-agenda-item" data-state="{{ $chipState($task) }}">
                                            <span class="dk-tc-dot" data-priority="{{ $task->priority->value }}" aria-hidden="true"></span>
                                            <div class="dk-tc-agenda-text">
                                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="dk-tc-task-title" data-test="task-calendar-agenda-item-{{ $task->id }}">{{ $task->title }}</a>
                                                <span class="dk-tc-meta">
                                                    {{ $task->status->label() }} · {{ $task->priority->label() }}
                                                    @if ($chipLabel !== null)<span class="dk-tc-flag">{{ $chipLabel }}</span>@endif
                                                </span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach

                        <div class="dk-tc-agenda-empty" x-show="! {{ Js::from($daysWithTasks->map->toDateString()->values()) }}.includes(selected)" @if ($daysWithTasks->contains(fn ($day) => $day->toDateString() === $agendaDefault)) x-cloak @endif>
                            <p>{{ __('tasks.calendar_no_tasks_on_day') }}</p>
                            @can('create', App\Models\Task::class)
                                <button type="button" class="dk-tc-btn" x-on:click="$wire.openCreateDrawer(selected)" data-test="task-calendar-agenda-add">
                                    <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />
                                    {{ __('tasks.add_task') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </div>
            @else
                {{-- Day / 4 days / week: one column per day (stacked rows on a phone). Tasks carry no time of day, so there is no hour grid. --}}
                <div class="dk-tc-cols" style="--tc-cols: {{ count($calendar['days']) }}" x-data="{ addOpen: {}, moreOpen: null }">
                    @foreach ($calendar['days'] as $day)
                        @php $isToday = $day->isSameDay($today); @endphp
                        <div @class(['dk-tc-col', 'is-today' => $isToday])>
                            <div class="dk-tc-col-head">
                                <span class="dk-tc-col-weekday">{{ $day->translatedFormat('l') }}</span>
                                <span class="dk-tc-date" @if ($isToday) aria-current="date" @endif>{{ $day->translatedFormat('j M') }}</span>
                            </div>
                            <div
                                wire:key="task-calendar-allday-{{ $day->toDateString() }}"
                                class="dk-tc-col-body"
                                data-test="task-calendar-allday-{{ $day->toDateString() }}"
                            >
                                @include('pages.tasks._calendar-day-cell', $cellPartial + ['day' => $day, 'chipLimit' => null, 'alignEnd' => false])
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <aside
            class="dk-tc-side"
            aria-labelledby="task-calendar-unscheduled-title"
            x-data="{
                q: '',
                sort: 'newest',
                matches(el) {
                    const needle = this.q.trim().toLowerCase();

                    return needle === '' || el.dataset.search.includes(needle);
                },
            }"
            data-test="task-calendar-unscheduled-panel"
        >
            <header class="dk-tc-side-head">
                <button
                    type="button"
                    class="dk-tc-side-toggle"
                    x-on:click="toggleSide()"
                    x-bind:aria-expanded="sideOpen ? 'true' : 'false'"
                    aria-controls="task-calendar-unscheduled-body"
                    x-bind:aria-label="sideOpen ? {{ Js::from(__('tasks.calendar_hide_unscheduled')) }} : {{ Js::from(__('tasks.calendar_show_unscheduled')) }}"
                    data-test="task-calendar-unscheduled-toggle"
                >
                    <flux:icon.inbox-stack variant="micro" class="dk-tc-side-icon size-4" aria-hidden="true" />
                    <span id="task-calendar-unscheduled-title" class="dk-tc-side-title">{{ __('tasks.unscheduled') }}</span>
                    <span class="dk-tc-count">{{ $calendar['unscheduledTotal'] }}</span>
                    <flux:icon.chevron-down variant="micro" class="dk-tc-side-chevron size-4" aria-hidden="true" />
                </button>
            </header>

            <div id="task-calendar-unscheduled-body" class="dk-tc-side-body" x-show="sideOpen">
                <p class="dk-tc-side-hint">{{ $canRescheduleAnyUnscheduled ? __('tasks.unscheduled_tasks_have_no_due_date_drag_one_onto_a_day_to_sch') : __('tasks.unscheduled_tasks_have_no_due_date') }}</p>

                @if ($unscheduled->count() > 1)
                    <div class="dk-tc-side-tools">
                        <label class="dk-tc-search">
                            <flux:icon.magnifying-glass variant="micro" class="size-4" aria-hidden="true" />
                            <span class="sr-only">{{ __('tasks.calendar_search_unscheduled') }}</span>
                            <input type="search" x-model.debounce.150ms="q" placeholder="{{ __('tasks.calendar_search_unscheduled') }}" data-test="task-calendar-unscheduled-search" />
                        </label>
                        <label class="dk-tc-sort">
                            <span class="sr-only">{{ __('tasks.calendar_sort_unscheduled') }}</span>
                            <select x-model="sort" data-test="task-calendar-unscheduled-sort">
                                <option value="newest">{{ __('tasks.calendar_sort_newest') }}</option>
                                <option value="priority">{{ __('tasks.calendar_sort_priority') }}</option>
                                <option value="title">{{ __('tasks.calendar_sort_title') }}</option>
                            </select>
                        </label>
                    </div>
                @endif

                <div class="dk-tc-side-list" x-data="taskDropList('task-calendar', (wire, id) => wire.rescheduleTask(id, ''))" x-ref="unscheduledList" data-test="task-calendar-unscheduled">
                    @forelse ($unscheduled as $newestRank => $task)
                        @php
                            $canReschedule = $task->created_by === auth()->id();
                            $assignees = $task->taskAssignees->pluck('employee')->filter();
                            $searchText = mb_strtolower($task->title.' '.$assignees->map->name->implode(' '));
                            $ranks = ['newest' => $newestRank, 'priority' => $byPriority[$task->id], 'title' => $byTitle[$task->id]];
                        @endphp
                        <article
                            class="dk-tc-task"
                            data-state="{{ $chipState($task) }}"
                            @if ($canReschedule) data-task-id="{{ $task->id }}" @endif
                            data-search="{{ $searchText }}"
                            x-show="matches($el)"
                            x-bind:style="'order:' + {{ Js::from($ranks) }}[sort]"
                            wire:key="task-unscheduled-{{ $task->id }}"
                        >
                            <div class="dk-tc-task-top">
                                <span class="dk-tc-dot" data-priority="{{ $task->priority->value }}" aria-hidden="true"></span>
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="dk-tc-task-title" data-test="task-calendar-unscheduled-title-{{ $task->id }}">{{ $task->title }}</a>
                            </div>

                            <div class="dk-tc-task-meta">
                                <span class="dk-tc-meta">{{ $task->priority->label() }} · {{ $task->status->label() }}</span>

                                @if ($assignees->isNotEmpty())
                                    <span class="dk-tc-avatars" title="{{ $assignees->map->name->implode(', ') }}">
                                        <span class="sr-only">{{ __('tasks.assignees') }}: {{ $assignees->map->name->implode(', ') }}</span>
                                        @foreach ($assignees->take(3) as $employee)
                                            <span aria-hidden="true">{{ Illuminate\Support\Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}</span>
                                        @endforeach
                                        @if ($assignees->count() > 3)
                                            <span aria-hidden="true">+{{ $assignees->count() - 3 }}</span>
                                        @endif
                                    </span>
                                @endif
                            </div>

                            @if ($canReschedule)
                                <div class="dk-tc-task-actions" x-data="{ picking: false }">
                                    <button
                                        type="button"
                                        class="dk-tc-link"
                                        x-show="! picking"
                                        x-on:click="picking = true; $nextTick(() => { $refs.due.focus(); try { $refs.due.showPicker() } catch (e) {} })"
                                        data-test="task-calendar-schedule-{{ $task->id }}"
                                    >
                                        <flux:icon.calendar-days variant="micro" class="size-4" aria-hidden="true" />
                                        {{ __('tasks.calendar_schedule') }}
                                    </button>
                                    <label x-show="picking" x-cloak class="dk-tc-due">
                                        <span class="sr-only">{{ __('tasks.calendar_schedule_task', ['title' => $task->title]) }}</span>
                                        <input
                                            type="date"
                                            x-ref="due"
                                            wire:change="rescheduleTask({{ $task->id }}, $event.target.value)"
                                            x-on:keydown.escape="picking = false"
                                            x-on:blur="if (! $el.value) picking = false"
                                            data-test="task-calendar-schedule-date-{{ $task->id }}"
                                        />
                                    </label>
                                </div>
                            @endif
                        </article>
                    @empty
                        <p class="dk-tc-side-empty">{{ __('tasks.drop_a_task_here_to_unschedule_it') }}</p>
                    @endforelse

                    @if ($unscheduled->count() > 1)
                        <p class="dk-tc-side-empty" style="order: 99999" x-show="q.trim() !== '' && ! [...$refs.unscheduledList.querySelectorAll('[data-search]')].some((el) => matches(el))" x-cloak>{{ __('tasks.calendar_no_unscheduled_match') }}</p>
                    @endif
                </div>

                @if ($calendar['unscheduledTotal'] > $unscheduled->count())
                    <p class="dk-tc-side-hint">{{ __('tasks.calendar_unscheduled_limit', ['shown' => $unscheduled->count(), 'total' => $calendar['unscheduledTotal']]) }}</p>
                @endif
            </div>
        </aside>
    </div>
</div>
