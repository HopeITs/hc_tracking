<?php

use App\Actions\Results\SaveActivitySchedule;
use App\Enums\ActivityKind;
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The activity calendar of the Logical Framework tab (T12 slice 3,
 * contracts §11.2, D-49): activities as rows, the months of the proposal's
 * years as columns. A first click schedules a month; a second click opens
 * its weeks. A continuous activity fills every month between its first and
 * last; an event keeps separate months.
 *
 * The scope picker switches between the proposal calendar and one center's
 * calendar; a center follows the proposal calendar until one of its months
 * is changed here. Every write goes through SaveActivitySchedule with the
 * version the row was rendered with, so a calendar changed by someone else
 * is refused instead of overwritten. Nested in the tab so a toggle re-renders
 * the grid only. A flat query count; no money.
 */
new class extends Component {
    #[Locked]
    public int $proposalId;

    /** '' = the proposal calendar; a project id = that center's calendar. */
    public string $scope = '';

    public bool $showStale = false;

    public bool $justSaved = false;

    public bool $showResetModal = false;

    #[Locked]
    public ?int $resettingActivityId = null;

    #[Locked]
    public string $resettingVersion = '';

    public function mount(int $proposalId): void
    {
        $this->proposalId = $proposalId;
    }

    public function updatedScope(): void
    {
        $this->showStale = false;
        $this->justSaved = false;
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'status', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end'])
            ->findOrFail($this->proposalId);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('update', [ActivitySchedule::class, $this->proposal]);
    }

    /**
     * The centers the proposal funds, for the scope picker.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return $this->proposal->projects()->select(['projects.id', 'projects.name'])->orderBy('projects.name')->get();
    }

    /**
     * The chosen center, or null for the proposal calendar (an unknown id
     * falls back to it).
     */
    #[Computed]
    public function scopeProjectId(): ?int
    {
        $projectId = (int) $this->scope;

        return $this->projects->contains('id', $projectId) ? $projectId : null;
    }

    /**
     * The calendar's columns: every month of the calendar years the proposal
     * period touches, flagged when it lies outside the period.
     *
     * @return list<array{key: string, label: string, title: string, year: int, inPeriod: bool}>
     */
    #[Computed]
    public function months(): array
    {
        [$first, $last] = ProposalFramework::scheduleWindow($this->proposal);
        $months = [];

        for ($cursor = $first->startOfYear(); $cursor->lte($last->endOfYear()); $cursor = $cursor->addMonth()) {
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->translatedFormat('M'),
                'title' => $cursor->translatedFormat('F Y'),
                'year' => $cursor->year,
                'inPeriod' => $cursor->betweenIncluded($first, $last),
            ];
        }

        return $months;
    }

    /**
     * One row per activity, grouped under its output, with the calendar shown
     * in the current scope and the version of the rows that scope stores.
     *
     * @return list<array{output: string, rows: list<array{id: int, statement: string, kind: ActivityKind, calendar: array<string, list<int>|null>, isOverride: bool, version: string}>}>
     */
    #[Computed]
    public function groups(): array
    {
        $activities = Result::query()
            ->proposalFramework($this->proposalId)
            ->where('type', ResultType::Activity->value)
            ->select(['id', 'statement', 'parent_id', 'activity_kind'])
            ->with('parent:id,statement')
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get();

        $projectId = $this->scopeProjectId;
        $schedules = ActivitySchedule::query()
            ->select(['id', 'result_id', 'project_id', 'month', 'weeks'])
            ->whereIn('result_id', $activities->modelKeys())
            ->where(fn ($query) => $projectId === null
                ? $query->whereNull('project_id')
                : $query->whereNull('project_id')->orWhere('project_id', $projectId))
            ->get()
            ->groupBy('result_id');

        $groups = [];

        foreach ($activities as $activity) {
            $rows = $schedules->get($activity->id, collect());
            $own = $projectId === null ? $rows : $rows->where('project_id', $projectId);
            $isOverride = $projectId !== null && $own->isNotEmpty();

            $groups[$activity->parent_id]['output'] = (string) $activity->parent?->statement;
            $groups[$activity->parent_id]['rows'][] = [
                'id' => $activity->id,
                'statement' => $activity->statement,
                'kind' => $activity->activity_kind ?? ActivityKind::Continuous,
                'calendar' => ActivitySchedule::calendarOf($isOverride || $projectId === null ? $own : $rows->whereNull('project_id')),
                'isOverride' => $isOverride,
                'version' => ActivitySchedule::versionOf(ActivitySchedule::calendarOf($own)),
            ];
        }

        return array_values($groups);
    }

    /**
     * Schedule a month (a second click opens its weeks in the browser).
     */
    public function toggleMonth(int $activityId, string $month, string $version): void
    {
        $calendar = $this->shownCalendar($activityId);

        if (array_key_exists($month, $calendar)) {
            return;
        }

        $calendar[$month] = null;
        $this->store($activityId, $calendar, $version);
    }

    public function removeMonth(int $activityId, string $month, string $version): void
    {
        $calendar = $this->shownCalendar($activityId);
        unset($calendar[$month]);

        $this->store($activityId, $calendar, $version);
    }

    /**
     * @param  list<int|string>  $weeks  empty = the whole month
     */
    public function saveWeeks(int $activityId, string $month, array $weeks, string $version): void
    {
        $calendar = $this->shownCalendar($activityId);
        $calendar[$month] = array_values(array_map('intval', $weeks));

        $this->store($activityId, $calendar, $version);
    }

    public function confirmReset(int $activityId, string $version): void
    {
        $this->resettingActivityId = $activityId;
        $this->resettingVersion = $version;
        $this->showResetModal = true;
    }

    public function cancelReset(): void
    {
        $this->resettingActivityId = null;
        $this->resettingVersion = '';
        $this->showResetModal = false;
    }

    /**
     * Drop the center's own months of one activity: it follows the proposal
     * calendar again.
     */
    public function resetCenter(): void
    {
        Gate::authorize('update', [ActivitySchedule::class, $this->proposal]);

        if ($this->resettingActivityId !== null && $this->scopeProjectId !== null) {
            $this->save($this->resettingActivityId, [], [], $this->resettingVersion);
        }

        $this->cancelReset();
    }

    public function showLatest(): void
    {
        $this->showStale = false;
        $this->forgetCalendar();
    }

    /**
     * The calendar the row shows in the current scope; a center without its
     * own starts from the proposal's.
     *
     * @return array<string, list<int>|null>
     */
    private function shownCalendar(int $activityId): array
    {
        foreach ($this->groups as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['id'] === $activityId) {
                    return $row['calendar'];
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, list<int>|null>  $calendar
     */
    private function store(int $activityId, array $calendar, string $version): void
    {
        $weeks = array_filter($calendar, fn (?array $monthWeeks): bool => $monthWeeks !== null && $monthWeeks !== []);

        $this->save($activityId, array_keys($calendar), $weeks, $version);
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<int>>  $weeks
     */
    private function save(int $activityId, array $months, array $weeks, string $version): void
    {
        $this->justSaved = false;

        try {
            app(SaveActivitySchedule::class)->handle($this->proposal, $activityId, $this->scopeProjectId, [
                'months' => $months,
                'weeks' => $weeks,
                'expected_version' => $version,
            ]);
        } catch (ValidationException $exception) {
            if (array_key_exists('schedule', $exception->errors())) {
                $this->showStale = true;
            } else {
                Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first());
            }

            $this->forgetCalendar();

            return;
        }

        $this->showStale = false;
        $this->justSaved = true;
        $this->forgetCalendar();
    }

    private function forgetCalendar(): void
    {
        unset($this->groups);
    }
};
?>

@php
    $groups = $this->groups;
    $months = $this->months;
    $canEdit = $this->canEdit;
    $isApproved = $this->proposal->status->isApprovedByDonor();
    $scopeProjectId = $this->scopeProjectId;
    $years = collect($months)->groupBy('year');
    $writeTargets = 'toggleMonth,removeMonth,saveWeeks,resetCenter';
@endphp

<section
    class="space-y-3"
    aria-labelledby="activity-calendar-heading"
    data-test="activity-calendar"
    data-tour="activity-calendar"
    x-data="{
        pop: null,
        rtl: document.documentElement.dir === 'rtl',
        open(cell) {
            const rect = cell.getBoundingClientRect();
            this.pop = {
                id: Number(cell.dataset.activity),
                month: cell.dataset.month,
                title: cell.dataset.title,
                weeks: JSON.parse(cell.dataset.weeks || '[]'),
                removable: cell.dataset.removable === 'true',
                version: cell.dataset.version,
                top: Math.min(rect.bottom + 6, window.innerHeight - 260),
                left: Math.max(8, Math.min(rect.left, window.innerWidth - 248)),
                cell,
            };
            this.$nextTick(() => this.$refs.popover?.querySelector('input')?.focus());
        },
        close(returnFocus = true) {
            const cell = this.pop?.cell;
            this.pop = null;
            if (returnFocus) { cell?.focus(); }
        },
        toggleWeek(week) {
            this.pop.weeks = this.pop.weeks.includes(week) ? this.pop.weeks.filter((w) => w !== week) : [...this.pop.weeks, week].sort();
        },
        move(event) {
            const steps = { ArrowRight: [0, this.rtl ? -1 : 1], ArrowLeft: [0, this.rtl ? 1 : -1], ArrowDown: [1, 0], ArrowUp: [-1, 0] };
            const step = steps[event.key];
            const cell = event.target.closest('[data-cell]');
            if (! step || ! cell) { return; }
            event.preventDefault();
            let [row, col] = [Number(cell.dataset.row) + step[0], Number(cell.dataset.col) + step[1]];
            while (true) {
                const next = this.$root.querySelector(`[data-cell][data-row='${row}'][data-col='${col}']`);
                if (! next) { return; }
                if (next.tagName === 'BUTTON') { cell.tabIndex = -1; next.tabIndex = 0; next.focus(); return; }
                [row, col] = [row + step[0], col + step[1]];
            }
        },
    }"
    x-on:keydown.escape.window="pop && close()"
>
    <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
        <div class="me-auto min-w-0">
            <h3 id="activity-calendar-heading" class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('results_framework.calendar_heading') }}</h3>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.calendar_intro') }}</p>
        </div>

        <p class="type-meta text-secondary-medium dark:text-ink-muted" role="status" aria-live="polite" data-test="calendar-save-state">
            <span wire:loading wire:target="{{ $writeTargets }}">{{ __('results_framework.calendar_saving') }}</span>
            @if ($justSaved)
                <span wire:loading.remove wire:target="{{ $writeTargets }}" class="inline-flex items-center gap-1">
                    <flux:icon.check variant="micro" class="size-3.5" aria-hidden="true" />{{ __('results_framework.calendar_saved') }}
                </span>
            @endif
        </p>

        @if ($this->projects->isNotEmpty())
            <div class="w-full sm:w-72">
                <x-ui.header-filter-select id="activity-calendar-scope" :label="__('results_framework.calendar_scope')" wire:model.live="scope" :value="(string) ($scopeProjectId ?? '')">
                    <option value="">{{ __('results_framework.calendar_scope_proposal') }}</option>
                    @foreach ($this->projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            </div>
        @endif
    </div>

    @if (! $canEdit)
        <p class="flex items-start gap-2 type-meta text-secondary-dark dark:text-ink" data-test="calendar-read-only">
            <flux:icon.lock-closed variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ $isApproved ? __('results_framework.calendar_read_only_approved') : __('results_framework.calendar_read_only') }}</span>
        </p>
    @elseif ($scopeProjectId !== null)
        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.calendar_uses_proposal') }}</p>
    @endif

    @if ($showStale)
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-status-critical/40 px-3 py-2.5 type-meta text-status-critical" role="alert" data-test="calendar-stale">
            <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
            <span class="me-auto">{{ __('results_framework.schedule_stale') }}</span>
            <x-ui.button size="sm" icon="arrow-path" wire:click="showLatest" wire:loading.attr="disabled" wire:target="showLatest">{{ __('results_framework.calendar_show_latest') }}</x-ui.button>
        </div>
    @endif

    @if ($groups === [])
        <p class="type-meta text-secondary-medium dark:text-ink-muted" data-test="calendar-empty">{{ __('results_framework.calendar_empty') }}</p>
    @else
        <x-ui.panel>
            <div class="overflow-x-auto" x-on:keydown="move($event)" x-on:scroll="pop && close(false)">
                <table class="w-max min-w-full border-separate border-spacing-0 text-sm" role="grid" aria-labelledby="activity-calendar-heading">
                    <thead>
                        <tr>
                            <th scope="col" rowspan="2" class="sticky start-0 z-20 min-w-40 max-w-44 sm:min-w-56 sm:max-w-72 border-b border-e border-secondary-soft bg-white px-3 py-2 text-start align-bottom type-label text-secondary-medium dark:border-line dark:bg-surface-1 dark:text-ink-muted">
                                {{ __('results_framework.calendar_activity_column') }}
                            </th>
                            @foreach ($years as $year => $yearMonths)
                                <th scope="colgroup" colspan="{{ count($yearMonths) }}" class="border-b border-e border-secondary-soft px-2 pt-2 pb-1 text-start type-label text-secondary-dark dark:border-line dark:text-ink">
                                    <span dir="ltr" class="tabular-nums">{{ $year }}</span>
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($months as $month)
                                <th scope="col" @class([
                                    'w-11 min-w-11 border-b border-secondary-soft px-0 pb-1.5 text-center text-[11px] font-semibold dark:border-line',
                                    'text-secondary-dark dark:text-ink' => $month['inPeriod'],
                                    'text-secondary-light dark:text-ink-faint' => ! $month['inPeriod'],
                                ]) abbr="{{ $month['title'] }}">{{ $month['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    @php
                        $rowIndex = 0;
                        $firstFocusable = true;
                    @endphp

                    @foreach ($groups as $group)
                        <tbody>
                            <tr>
                                <th scope="rowgroup" colspan="{{ count($months) + 1 }}" class="bg-ground px-3 py-1.5 text-start dark:bg-surface-2">
                                    <span class="sticky start-3 block max-w-[calc(100vw-4rem)] truncate type-meta font-semibold text-secondary-medium dark:text-ink-muted" dir="auto">{{ $group['output'] }}</span>
                                </th>
                            </tr>

                            @foreach ($group['rows'] as $row)
                                @php
                                    $calendar = $row['calendar'];
                                    $isContinuous = $row['kind'] === ActivityKind::Continuous;
                                    $scheduledKeys = array_keys($calendar);
                                    $firstKey = $scheduledKeys[0] ?? null;
                                    $lastKey = $scheduledKeys[count($scheduledKeys) - 1] ?? null;
                                    $rowIndex++;
                                @endphp
                                <tr wire:key="calendar-row-{{ $row['id'] }}-{{ $scopeProjectId ?? 'p' }}" data-test="calendar-row-{{ $row['id'] }}">
                                    <th scope="row" class="sticky start-0 z-10 min-w-40 max-w-44 sm:min-w-56 sm:max-w-72 border-b border-e border-secondary-soft bg-white px-3 py-2 text-start align-top font-normal dark:border-line dark:bg-surface-1">
                                        <span class="flex items-start gap-2">
                                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-transparent ring-2 ring-secondary-medium dark:ring-ink-muted" aria-hidden="true"></span>
                                            <span class="min-w-0">
                                                <span class="line-clamp-2 text-secondary-dark dark:text-ink" dir="auto">{{ $row['statement'] }}</span>
                                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                    <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ $row['kind']->label() }}</span>
                                                    @if ($calendar === [])
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-secondary-soft px-2 py-0.5 text-[11px] font-semibold text-secondary-dark dark:bg-surface-3 dark:text-ink" data-test="no-calendar-{{ $row['id'] }}">
                                                            <flux:icon.calendar variant="micro" class="size-3" aria-hidden="true" />{{ __('results_framework.no_calendar') }}
                                                        </span>
                                                    @endif
                                                    @if ($row['isOverride'])
                                                        <span class="inline-flex items-center rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-dark dark:border-line-strong dark:text-ink" data-test="center-calendar-{{ $row['id'] }}">{{ __('results_framework.calendar_center_own') }}</span>
                                                        @if ($canEdit)
                                                            <x-ui.button tone="ghost" size="xs" icon="arrow-uturn-left" wire:click="confirmReset({{ $row['id'] }}, '{{ $row['version'] }}')" :aria-label="__('results_framework.calendar_reset_center')" data-test="reset-center-{{ $row['id'] }}" />
                                                        @endif
                                                    @endif
                                                </span>
                                            </span>
                                        </span>
                                    </th>

                                    @foreach ($months as $colIndex => $month)
                                        @php
                                            $key = $month['key'];
                                            $isScheduled = array_key_exists($key, $calendar);
                                            $weeks = $calendar[$key] ?? null;
                                            $previousScheduled = $colIndex > 0 && array_key_exists($months[$colIndex - 1]['key'], $calendar);
                                            $nextScheduled = isset($months[$colIndex + 1]) && array_key_exists($months[$colIndex + 1]['key'], $calendar);
                                            $isJoined = $isContinuous;
                                            $removable = ! $isContinuous || $key === $firstKey || $key === $lastKey;
                                            $label = match (true) {
                                                ! $isScheduled => __('results_framework.calendar_month_unscheduled', ['month' => $month['title']]),
                                                $weeks === null => __('results_framework.calendar_month_scheduled', ['month' => $month['title']]),
                                                default => __('results_framework.calendar_month_weeks', ['month' => $month['title'], 'weeks' => implode(', ', $weeks)]),
                                            };
                                            $markClasses = match (true) {
                                                ! $isScheduled => null,
                                                // One continuous run reads as one bar: only its two ends are rounded and closed.
                                                $isJoined => 'h-5 w-full border-y border-secondary-medium bg-secondary-soft dark:border-ink-muted dark:bg-surface-3'
                                                    .(! $previousScheduled ? ' ms-1 rounded-s-full border-s' : '')
                                                    .(! $nextScheduled ? ' me-1 rounded-e-full border-e' : ''),
                                                default => 'mx-auto h-5 w-7 rounded-full border border-secondary-medium bg-secondary-soft dark:border-ink-muted dark:bg-surface-3',
                                            };
                                            $isFocusable = $canEdit && $month['inPeriod'];
                                        @endphp
                                        <td class="h-11 border-b border-secondary-soft p-0 dark:border-line" role="gridcell">
                                            @if ($isFocusable)
                                                <button
                                                    type="button"
                                                    data-test="month-cell-{{ $row['id'] }}-{{ $key }}" data-scheduled="{{ $isScheduled ? 'true' : 'false' }}"
                                                    data-cell data-row="{{ $rowIndex }}" data-col="{{ $colIndex }}"
                                                    data-activity="{{ $row['id'] }}" data-month="{{ $key }}" data-title="{{ $month['title'] }}"
                                                    data-weeks="{{ json_encode($weeks ?? []) }}" data-removable="{{ $removable ? 'true' : 'false' }}" data-version="{{ $row['version'] }}"
                                                    tabindex="{{ $firstFocusable ? 0 : -1 }}"
                                                    aria-label="{{ $label }}"
                                                    aria-pressed="{{ $isScheduled ? 'true' : 'false' }}"
                                                    @if ($isScheduled)
                                                        x-on:click="open($el)"
                                                    @else
                                                        wire:click="toggleMonth({{ $row['id'] }}, '{{ $key }}', '{{ $row['version'] }}')"
                                                    @endif
                                                    wire:loading.attr="disabled" wire:target="{{ $writeTargets }}"
                                                    class="group relative flex h-11 w-full items-center outline-none hover:bg-secondary-soft/50 focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary disabled:cursor-wait dark:hover:bg-white/[0.04] dark:focus-visible:ring-brand"
                                                >
                                                    @if ($markClasses !== null)
                                                        <span class="relative block {{ $markClasses }}" aria-hidden="true">
                                                            @if ($weeks !== null)
                                                                <span class="absolute inset-x-0 bottom-1 flex justify-center gap-px">
                                                                    @foreach (range(1, 5) as $week)
                                                                        <span @class(['h-1 w-1 rounded-full', 'bg-secondary-dark dark:bg-ink' => in_array($week, $weeks, true), 'bg-secondary-light/60 dark:bg-ink-faint/50' => ! in_array($week, $weeks, true)])></span>
                                                                    @endforeach
                                                                </span>
                                                            @endif
                                                        </span>
                                                    @else
                                                        <span class="mx-auto size-1.5 rounded-full bg-secondary-light/70 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100 dark:bg-ink-faint" aria-hidden="true"></span>
                                                    @endif
                                                </button>
                                                @php
                                                    $firstFocusable = false;
                                                @endphp
                                            @else
                                                <span
                                                    data-test="month-cell-{{ $row['id'] }}-{{ $key }}" data-scheduled="{{ $isScheduled ? 'true' : 'false' }}"
                                                    data-cell data-row="{{ $rowIndex }}" data-col="{{ $colIndex }}"
                                                    @if (! $month['inPeriod']) title="{{ __('results_framework.month_outside_period') }}" @endif
                                                    @class([
                                                        'relative flex h-11 w-full items-center',
                                                        'cursor-not-allowed bg-secondary-soft/40 dark:bg-surface-2/50' => ! $month['inPeriod'],
                                                    ])
                                                >
                                                    <span class="sr-only">{{ $month['inPeriod'] ? $label : __('results_framework.month_outside_period') }}</span>
                                                    @if ($markClasses !== null)
                                                        <span class="relative block {{ $markClasses }}" aria-hidden="true"></span>
                                                    @endif
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </x-ui.panel>
    @endif

    {{-- The weeks of one scheduled month: opened by a second click on its cell. --}}
    <div
        x-ref="popover"
        x-show="pop !== null"
        x-cloak
        x-transition.opacity
        x-on:click.outside="pop && close(false)"
        x-bind:style="pop ? `top: ${pop.top}px; left: ${pop.left}px` : ''"
        class="fixed z-50 w-60 rounded-xl border border-secondary-soft bg-white p-3 shadow-card dark:border-line dark:bg-surface-2 dark:shadow-raised"
        role="dialog"
        aria-modal="false"
        x-bind:aria-label="pop ? @js(__('results_framework.calendar_weeks_heading', ['month' => '__MONTH__'])).replace('__MONTH__', pop.title) : ''"
        data-test="calendar-weeks-popover"
    >
        <template x-if="pop">
            <div class="space-y-3">
                <p class="text-sm font-semibold text-secondary-dark dark:text-ink" x-text="@js(__('results_framework.calendar_weeks_heading', ['month' => '__MONTH__'])).replace('__MONTH__', pop.title)"></p>

                <div class="flex justify-between gap-1" role="group" x-bind:aria-label="@js(__('results_framework.calendar_weeks_heading', ['month' => '__MONTH__'])).replace('__MONTH__', pop.title)">
                    @foreach (range(1, 5) as $week)
                        <label class="flex flex-col items-center gap-1 type-meta text-secondary-dark dark:text-ink">
                            <input type="checkbox" class="size-4 accent-primary dark:accent-brand" x-bind:checked="pop.weeks.includes({{ $week }})" x-on:change="toggleWeek({{ $week }})" aria-label="{{ __('results_framework.calendar_week', ['number' => $week]) }}">
                            <span aria-hidden="true">{{ __('results_framework.calendar_week_short', ['number' => $week]) }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button size="sm" tone="primary" x-on:click="$wire.saveWeeks(pop.id, pop.month, pop.weeks, pop.version); close()">{{ __('results_framework.calendar_save_weeks') }}</x-ui.button>
                    <x-ui.button size="sm" x-on:click="$wire.saveWeeks(pop.id, pop.month, [], pop.version); close()">{{ __('results_framework.calendar_whole_month') }}</x-ui.button>
                </div>

                <div class="border-t border-secondary-soft pt-2 dark:border-line">
                    <template x-if="pop.removable">
                        <x-ui.button size="sm" tone="ghost" icon="x-mark" x-on:click="$wire.removeMonth(pop.id, pop.month, pop.version); close()">{{ __('results_framework.calendar_remove_month') }}</x-ui.button>
                    </template>
                    <template x-if="! pop.removable">
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.calendar_inner_month') }}</p>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <x-ui.confirm-modal
        wire:model="showResetModal"
        close="cancelReset"
        tone="neutral"
        :title="__('results_framework.calendar_reset_title')"
        :message="__('results_framework.calendar_reset_message')"
        confirm="resetCenter"
        :confirm-label="__('results_framework.calendar_reset_center')"
        confirm-test="confirm-reset-center"
    />
</section>
