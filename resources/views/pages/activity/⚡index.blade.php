<?php

use App\Models\Activity;
use App\Models\Program;
use App\Models\User;
use App\Notifications\ActivityPresenter;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;
use App\Support\ActivitySearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.activity_log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'created_at')]
    public string $sortField = 'created_at';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    public function updated(string $property): void
    {
        if (in_array($property, ['module', 'actor', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Toggles direction when the same column is clicked again, otherwise
     * switches to the new column with a predictable ascending default.
     */
    public function setSort(string $field): void
    {
        if (! in_array($field, ['created_at', 'module'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * The two headline numbers are buttons: "today" and "last 7 days" set the
     * date range, pressing the active one again clears it.
     */
    public function showRange(string $range): void
    {
        if (! in_array($range, ['today', 'week'], true)) {
            return;
        }

        if ($this->activeRange() === $range) {
            $this->from = '';
            $this->to = '';
        } else {
            $today = CarbonImmutable::today();
            $this->from = ($range === 'today' ? $today : $today->subDays(6))->toDateString();
            $this->to = $today->toDateString();
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('module', 'actor', 'search', 'from', 'to');
        $this->resetPage();
    }

    public function activeRange(): ?string
    {
        $today = CarbonImmutable::today();

        return match (true) {
            $this->from === $today->toDateString() && $this->to === $today->toDateString() => 'today',
            $this->from === $today->subDays(6)->toDateString() && $this->to === $today->toDateString() => 'week',
            default => null,
        };
    }

    public function hasFilters(): bool
    {
        return $this->module !== '' || $this->actor !== '' || trim($this->search) !== '' || $this->from !== '' || $this->to !== '';
    }

    public function rangeIsBackwards(): bool
    {
        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);

        return $from !== null && $to !== null && $from->greaterThan($to);
    }

    /**
     * @return Builder<Activity>
     */
    private function filteredQuery(bool $withDates = true): Builder
    {
        $term = trim($this->search);
        $from = $withDates ? $this->parseDate($this->from) : null;
        $to = $withDates ? $this->parseDate($this->to) : null;

        return Activity::query()
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when($this->actor === 'system', fn (Builder $query) => $query->whereNull('actor_id'))
            ->when(ctype_digit($this->actor), fn (Builder $query) => $query->where('actor_id', (int) $this->actor))
            ->when($term !== '', fn (Builder $query) => $query->where(ActivitySearch::matching($term)))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()));
    }

    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        $sortingByModule = $this->sortField === 'module';

        $areaOrder = $sortingByModule ? $this->areasInDisplayOrder() : [];
        $areaPlaceholders = implode(', ', array_fill(0, max(1, count($areaOrder)), '?'));

        return $this->filteredQuery()
            ->with('actor:id,hr_user_id,employee_id')
            // Unknown areas (FIELD = 0) go last, then the order the reader sees.
            ->when($sortingByModule && $areaOrder !== [], fn (Builder $query) => $query
                ->orderByRaw("FIELD(module, {$areaPlaceholders}) = 0", $areaOrder)
                ->orderByRaw("FIELD(module, {$areaPlaceholders})", $areaOrder))
            ->orderBy('created_at', $sortingByModule ? 'desc' : $this->sortDirection)
            ->orderBy('id', $sortingByModule ? 'desc' : $this->sortDirection)
            ->paginate(50);
    }

    /**
     * How many entries each shown day really has under the current filters,
     * so a day split across two pages never reads as a smaller number.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function dayTotals(): array
    {
        $days = collect($this->activities->items())
            ->map(fn (Activity $activity) => $activity->created_at?->toDateString())
            ->filter()
            ->unique()
            ->values();

        if ($days->isEmpty()) {
            return [];
        }

        $first = CarbonImmutable::parse($days->min())->startOfDay();
        $last = CarbonImmutable::parse($days->max())->endOfDay();

        return $this->filteredQuery()
            ->whereBetween('created_at', [$first, $last])
            ->get(['created_at'])
            ->countBy(fn (Activity $activity) => $activity->created_at?->toDateString())
            ->only($days->all())
            ->all();
    }

    /**
     * Today and the last 7 days under the area, person and search filters,
     * ignoring only the date range, so pressing a number shows exactly that
     * many entries.
     *
     * @return array{today: int, week: int}
     */
    #[Computed]
    public function summary(): array
    {
        $todayStart = CarbonImmutable::today();

        return [
            'today' => $this->filteredQuery(withDates: false)->where('created_at', '>=', $todayStart)->count(),
            'week' => $this->filteredQuery(withDates: false)->where('created_at', '>=', $todayStart->subDays(6))->count(),
        ];
    }

    /**
     * Records on this page that still exist, one query per type.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function existing(): array
    {
        $idsByType = [];

        foreach ($this->activities->items() as $activity) {
            $idsByType[$activity->subject_type][] = $activity->subject_id;
        }

        return app(SubjectLinks::class)->existing($idsByType);
    }

    /**
     * Program names for every activity on this page that is tied to one —
     * the thread that ties a delivery entry or a funding entry back to the
     * program behind it.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function programs(): array
    {
        $programIds = collect($this->activities->items())
            ->pluck('program_id')
            ->filter()
            ->unique()
            ->values();

        if ($programIds->isEmpty()) {
            return [];
        }

        return Program::query()->whereIn('id', $programIds)->get()->pluck('name', 'id')->all();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function actorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', Activity::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
            ->orderByName()
            ->get(['id', 'hr_user_id']);
    }

    /**
     * The ledger's accent family for a module: funding entries run teal, a
     * risk runs warn amber, everything else runs the neutral program-delivery
     * ink.
     */
    protected function moduleTone(string $module): string
    {
        return match ($module) {
            'donors', 'proposals', 'funding-agreements', 'transfers', 'accounts' => 'funding',
            'risks' => 'warn',
            default => 'neutral',
        };
    }

    /**
     * The area keys ordered by the name the reader sees ("Users & roles"
     * for access-management), in the chosen direction, so sorting by Area
     * reads alphabetically in either language.
     *
     * @return list<string>
     */
    private function areasInDisplayOrder(): array
    {
        $presenter = app(NotificationPresenter::class);

        $areas = collect(array_keys(config('notifications.modules')))
            ->sortBy(fn (string $module): string => mb_strtolower($presenter->moduleLabel($module)))
            ->values();

        return array_values(($this->sortDirection === 'desc' ? $areas->reverse() : $areas)->all());
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
    $notificationPresenter = app(NotificationPresenter::class);
    $links = app(SubjectLinks::class);

    $tagClasses = fn (string $tone): string => match ($tone) {
        'funding' => 'border-funding/40 text-funding',
        'warn' => 'border-status-warn/40 text-status-warn',
        default => 'border-secondary-light text-secondary-medium dark:text-ink-muted',
    };
@endphp

<x-desk.register
    class="@container/directory"
    heading-id="activity-log-heading"
    :title="__('activity.log_title')"
    :count="$this->activities->total()"
>
    <x-slot:search>
        <x-desk.register-search :label="__('activity.filters.search')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>
    <x-slot:filters>

            <x-ui.header-filter-select :label="__('activity.filters.module')" wire:model.live="module" :value="$module">
                <option value="">{{ __('activity.filters.all_modules') }}</option>
                @foreach (array_keys(config('notifications.modules')) as $option)
                    <option value="{{ $option }}">{{ $notificationPresenter->moduleLabel($option) }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <div class="contents" x-data="{ open: @js($actor !== '' || $from !== '' || $to !== '') }">
                <button type="button" class="dk-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" data-test="more-filters-button">
                    <flux:icon.adjustments-horizontal variant="micro" class="size-4" />
                    {{ __('activity.filters.more') }}
                </button>

                <div class="contents" x-show="open" x-cloak>
                    <x-ui.header-filter-select :label="__('activity.filters.actor')" wire:model.live="actor" :value="$actor" searchable>
                        <option value="">{{ __('activity.filters.all_actors') }}</option>
                        <option value="system">{{ __('activity.filters.system') }}</option>
                        @foreach ($this->actorOptions as $person)
                            <option value="{{ (string) $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </x-ui.header-filter-select>

                    <x-ui.input type="date" :label="__('activity.filters.from')" wire:model.live="from" />
                    <x-ui.input type="date" :label="__('activity.filters.to')" wire:model.live="to" />
                </div>
            </div>
        </x-slot:filters>


    @php
        $summary = $this->summary;
        $activeRange = $this->activeRange();
        $headline = [
            'today' => ['label' => __('activity.summary.today'), 'count' => $summary['today']],
            'week' => ['label' => __('activity.summary.week'), 'count' => $summary['week']],
        ];
    @endphp
    <div role="group" aria-label="{{ __('activity.summary.heading') }}" class="grid grid-cols-2 gap-3">
        @foreach ($headline as $range => $tile)
            <button
                type="button"
                wire:click="showRange('{{ $range }}')"
                aria-pressed="{{ $activeRange === $range ? 'true' : 'false' }}"
                data-activity-range="{{ $range }}"
                class="cursor-pointer rounded-xl border border-board-line bg-board p-3.5 text-start hover:bg-board-head focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-secondary-medium aria-pressed:border-secondary-medium dark:shadow-raised dark:border-transparent"
            >
                <span class="type-label block text-secondary-medium dark:text-ink-muted">{{ $tile['label'] }}</span>
                <span class="type-heading mt-1 block tabular-nums">{{ $tile['count'] }}</span>
            </button>
        @endforeach
    </div>

    @if ($this->activities->isEmpty())
        <x-ui.empty-state icon="document-text" :heading="$this->hasFilters() ? __('activity.empty_filtered') : __('activity.empty')" :body="$this->rangeIsBackwards() ? __('activity.range_backwards') : null">
            @if ($this->hasFilters())
                <x-slot:action>
                    <x-ui.button tone="ghost" size="sm" wire:click="resetFilters" data-activity-reset>{{ __('common.reset_filters') }}</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    @else
        @php
            $groupedByDay = $sortField === 'created_at';
            $days = $groupedByDay
                ? collect($this->activities->items())->groupBy(fn ($activity) => $activity->created_at?->toDateString())
                : collect([null => collect($this->activities->items())]);
            $today = CarbonImmutable::today()->toDateString();
            $yesterday = CarbonImmutable::yesterday()->toDateString();
        @endphp

        <div class="flex flex-col gap-4" wire:loading.class="opacity-50" wire:target="module, actor, search, from, to, setSort, showRange, resetFilters, previousPage, nextPage, gotoPage">
            @foreach ($days as $day => $dayActivities)
                @php
                    $dayLabel = match (true) {
                        ! $groupedByDay => null,
                        $day === $today => __('notifications.sections.today'),
                        $day === $yesterday => __('notifications.sections.yesterday'),
                        default => $dayActivities->first()->created_at?->translatedFormat(__('formats.date_wire')),
                    };
                @endphp

                <x-ui.data-table x-data="{ open: true }" wire:key="day-{{ $day ?? 'all' }}">
                    <x-slot:head>
                        @include('pages.activity._sortable-th', ['field' => 'created_at', 'label' => __('activity.ledger.time'), 'class' => 'w-16!'])
                        @include('pages.activity._sortable-th', ['field' => 'module', 'label' => __('activity.filters.module'), 'class' => 'w-32!'])
                        <th>{{ __('activity.ledger.entry') }}</th>
                        <th class="w-44!">{{ __('activity.ledger.linked_to') }}</th>
                        <th class="w-24!"></th>
                    </x-slot:head>

                    @if ($dayLabel !== null)
                        <tr class="bg-board-head">
                            <td colspan="5" class="p-0">
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    :aria-expanded="open.toString()"
                                    class="flex w-full cursor-pointer items-center justify-between gap-3 px-3.5 py-2 text-start hover:bg-board-line/40"
                                >
                                    <span class="inline-flex min-w-0 items-center gap-2">
                                        <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 text-secondary-medium rtl:-scale-x-100 dark:text-ink-muted" x-bind:class="open && 'rotate-90 rtl:rotate-90'" style="transition: transform 150ms" />
                                        <span class="type-label truncate text-secondary-dark dark:text-ink">{{ $dayLabel }}</span>
                                    </span>
                                    <span class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('activity.ledger.entry_count', $this->dayTotals[$day] ?? $dayActivities->count(), ['count' => $this->dayTotals[$day] ?? $dayActivities->count()]) }}</span>
                                </button>
                            </td>
                        </tr>
                    @endif

                    @foreach ($dayActivities as $activity)
                        @php
                            $tone = $this->moduleTone($activity->module);
                            $exists = in_array($activity->subject_id, $this->existing[$activity->subject_type] ?? [], true);
                            $url = $exists ? $links->routeFor($activity->subject_type, $activity->subject_id) : null;
                            $programName = $activity->program_id !== null ? ($this->programs[$activity->program_id] ?? null) : null;
                        @endphp
                        <tr wire:key="log-{{ $activity->id }}" @if ($dayLabel !== null) x-show="open" @endif>
                            <x-ui.data-table.cell :label="__('activity.ledger.time')" numeric>
                                @if ($groupedByDay)
                                    <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->format('H:i') }}</time>
                                @else
                                    <time datetime="{{ $activity->created_at?->toIso8601String() }}" class="whitespace-nowrap">{{ $activity->created_at?->translatedFormat(__('formats.date_short')) }} · {{ $activity->created_at?->format('H:i') }}</time>
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('activity.filters.module')">
                                <span class="{{ $tagClasses($tone) }} inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase whitespace-nowrap">
                                    {{ $notificationPresenter->moduleLabel($activity->module) }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <p class="text-sm leading-snug">{{ $presenter->sentence($activity) }}</p>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('activity.ledger.linked_to')">
                                @if ($programName !== null)
                                    <a href="{{ route('programs.show', tap(new Program, fn ($program) => $program->id = $activity->program_id)) }}" wire:navigate class="truncate text-secondary-dark hover:text-status-critical hover:underline dark:text-ink">
                                        {{ $programName }}
                                    </a>
                                @else
                                    <span class="text-secondary-light dark:text-ink-faint">—</span>
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell class="@3xl:text-end">
                                @if ($url !== null)
                                    <a href="{{ $url }}" wire:navigate class="font-semibold text-status-critical hover:underline max-sm:inline-flex max-sm:min-h-11 max-sm:items-center">{{ __('activity.open_record') }}</a>
                                @elseif (! $exists)
                                    <span class="text-secondary-medium dark:text-ink-muted">{{ __('activity.record_gone') }}</span>
                                @endif
                            </x-ui.data-table.cell>
                        </tr>
                    @endforeach
                </x-ui.data-table>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->activities" />
    @endif
</x-desk.register>
