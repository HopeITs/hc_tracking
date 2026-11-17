<?php

use App\Livewire\Concerns\Calendar\BuildsCalendarGrid;
use App\Livewire\Concerns\Calendar\FiltersCalendarTypes;
use App\Livewire\Concerns\Calendar\FindsFocusedTypeDates;
use App\Livewire\Concerns\Calendar\ManagesCalendarTasks;
use App\Livewire\Concerns\Calendar\PresentsCalendarItems;
use App\Livewire\Concerns\Calendar\ScopesCalendarPeople;
use App\Livewire\Concerns\Calendar\ShowsCalendarDay;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\NavigatesCalendarPeriods;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.calendar')] class extends Component {
    use BuildsCalendarGrid;
    use ChecksFinanceVisibility;
    use FiltersCalendarTypes;
    use FindsFocusedTypeDates;
    use ManagesCalendarTasks;
    use NavigatesCalendarPeriods;
    use PresentsCalendarItems;
    use ScopesCalendarPeople;
    use ShowsCalendarDay;

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $anchorDate = '';

    #[Url(except: 'month')]
    public string $viewMode = 'month';

    #[Url(as: 'types', except: '')]
    public string $shownTypes = '';

    /** @var array<int, string> */
    #[Url(as: 'responsible', except: [])]
    public array $filterEmployeeIds = [];

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }
};
?>

@php
    $calendar = $this->calendar;
    $today = \Carbon\CarbonImmutable::today();
    $hasEmployee = auth()->user()->employee_id !== null;
    $canAddTask = auth()->user()->can('create', App\Models\Task::class);
    // Someone who may not add a task gets no Add button and no click-a-day
    // menu, instead of an empty dropdown.
    $canAdd = $canAddTask;

    $proposalTone = function (\App\Models\Proposal $proposal) use ($today): string {
        if ($proposal->actual_date_end !== null) {
            return 'bg-status-good-soft text-status-good';
        }

        if ($proposal->calendarEndDate()->isBefore($today)) {
            return 'bg-status-critical-soft text-primary dark:text-brand-text';
        }

        return 'bg-status-active-soft text-status-active';
    };

    $projectTone = 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';

    // A dot in front of each chip carries the item TYPE, independent of the
    // tone above (which carries its STATUS) — so a Done task and a
    // received transfer both render green fills but stay visually distinguishable.
    $typeDotClasses = [
        'task' => 'bg-status-warn',
        'report' => 'bg-primary dark:bg-status-critical',
        'monthly_report' => 'bg-transparent ring-1 ring-inset ring-primary dark:ring-status-critical',
        'proposal' => 'bg-status-active',
        'transfer' => 'bg-status-good',
        'donor' => 'bg-secondary-dark dark:bg-ink-muted',
        'project' => 'bg-secondary-medium',
    ];

    // Literal ring-*/text-* utilities kept as their own arrays (rather than
    // derived from $typeDotClasses with str_replace) because Tailwind only
    // generates a class it can find as a literal token while scanning this
    // file — a runtime-built "ring-status-active" string would never compile.
    $typeRingClasses = [
        'task' => 'ring-status-warn',
        'report' => 'ring-primary',
        'monthly_report' => 'ring-primary',
        'proposal' => 'ring-status-active',
        'transfer' => 'ring-status-good',
        'donor' => 'ring-secondary-dark dark:ring-ink-muted',
        'project' => 'ring-secondary-medium',
    ];

    $typeTextClasses = [
        'task' => 'text-status-warn',
        'report' => 'text-primary dark:text-brand-text',
        'monthly_report' => 'text-primary dark:text-brand-text',
        'proposal' => 'text-status-active',
        'transfer' => 'text-status-good',
        'donor' => 'text-secondary-dark dark:text-ink-faint',
        'project' => 'text-secondary-medium',
    ];

    $typeLabels = [
        'task' => __('tasks.task'),
        'report' => __('calendar.report_deadline'),
        'monthly_report' => __('calendar.monthly_report_due'),
        'proposal' => __('calendar.proposal_deadline'),
        'transfer' => __('calendar.transfer'),
        'donor' => __('calendar.donor_follow_up'),
        'project' => __('calendar.project_timeline'),
    ];

    // Which per-day grouping(s) in $calendar back each type, so the grid can
    // tell whether a given day carries the focused type without re-querying.
    $focusedGroupKeys = $this->typeDayGroups();

    // Every per-day grouping, regardless of type — used to tell whether a
    // day has anything on it at all, so its content area can open the
    // day-detail list instead of the "add" menu.
    $allGroupKeys = ['tasksByDay', 'reportsByDay', 'monthlyReportsByDay', 'proposalsByDay', 'transfersByDay', 'donorFollowUpsByDay', 'donorReportsByDay', 'projectStartsByDay', 'projectEndsByDay', 'reviewMeetingsByDay'];

    $focusedType = $this->focusedType();

    $selectedFilterCount = count($filterEmployeeIds);

    // Dates name the year only when it is not this year.
    $periodStart = $calendar['days'][0] ?? $calendar['month'];
    $periodEnd = $calendar['days'] === null ? $calendar['month'] : end($calendar['days']);
    $showsOtherYear = $periodStart->year !== $today->year || $periodEnd->year !== $today->year;
    $periodHeading = match ($viewMode) {
        'month' => $calendar['month']->translatedFormat(__('formats.month_year')),
        'day' => $periodStart->translatedFormat(__($showsOtherYear ? 'formats.date_long' : 'formats.date_long_no_year')),
        default => $periodStart->translatedFormat(__($showsOtherYear ? 'formats.date' : 'formats.date_short')).' – '.$periodEnd->translatedFormat(__($showsOtherYear ? 'formats.date' : 'formats.date_short')),
    };
@endphp

<x-desk.register
    class="admin-directory @container/directory"
    heading-id="calendar-heading"
    :title="$this->calendarTitle()"
>


    @include('pages.calendar._index-toolbar')

    @if (! $hasEmployee)
        <x-ui.panel>
            <x-ui.empty-state icon="user" :heading="__('calendar.no_linked_employee')" :body="__('calendar.link_your_account_to_see_your_calendar')" />
        </x-ui.panel>
    @else
        @include('pages.calendar._index-focus-banner')

        <div class="grid gap-4 @6xl/directory:grid-cols-[minmax(0,1fr)_16rem]">
        <div
            class="relative overflow-hidden rounded-xl border border-secondary-soft dark:border-line"
            x-data="{ menuOpen: false, menuX: 0, menuY: 0, menuDate: '' }"
            x-on:click.away="menuOpen = false"
            x-on:keydown.escape.window="menuOpen = false"
        >
            @if ($viewMode === 'month')
                @include('pages.calendar._index-month-grid')
            @else
                @include('pages.calendar._index-period-grid')
            @endif

            <div
                x-show="menuOpen"
                style="display: none"
                x-cloak
                :style="{ top: menuY + 'px', left: menuX + 'px' }"
                class="fixed z-50 min-w-40 rounded-md border border-secondary-soft bg-white py-1 shadow-lg dark:border-line dark:bg-surface-3 dark:shadow-raised"
                data-test="calendar-context-menu"
            >
                @if ($canAddTask)
                    <button
                        type="button"
                        x-on:click="menuOpen = false; $wire.openCreateTaskModal(menuDate)"
                        class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-start text-sm text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-white/[0.06]"
                        data-test="calendar-context-add-task"
                    >
                        <flux:icon.clipboard-document-check variant="micro" class="size-4" />
                        {{ __('tasks.add_task') }}
                    </button>
                @endif
            </div>
        </div>

        @include('pages.calendar._index-focus-list')

        @include('pages.calendar._index-unscheduled')
        </div>
    @endif

    @include('pages.calendar._index-task-modal')

    @include('pages.calendar._index-day-modal')
</x-desk.register>
