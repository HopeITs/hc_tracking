<?php

use App\Models\Activity;
use App\Models\Program;
use App\Models\User;
use App\Notifications\ActivityPresenter;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.activity_log')] class extends Component {
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

    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        $term = trim($this->search);
        $sortingByModule = $this->sortField === 'module';

        return Activity::query()
            ->with('actor:id,hr_user_id')
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when(ctype_digit($this->actor), fn (Builder $query) => $query->where('actor_id', (int) $this->actor))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('event', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('properties', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->where('created_at', '>=', CarbonImmutable::parse($this->from)->startOfDay()))
            ->when($this->validDate($this->to), fn (Builder $query) => $query->where('created_at', '<=', CarbonImmutable::parse($this->to)->endOfDay()))
            ->when($sortingByModule, fn (Builder $query) => $query->orderBy('module', $this->sortDirection))
            ->orderBy('created_at', $sortingByModule ? 'desc' : $this->sortDirection)
            ->orderBy('id', $sortingByModule ? 'desc' : $this->sortDirection)
            ->paginate(50);
    }

    /**
     * The statement summary, independent of the filters below: today's
     * count, the last 7 days, and today's busiest module and person — read
     * the way a reconciled ledger opens with its running totals.
     *
     * @return array{today: int, week: int, module: array{label: string, count: int}|null, actor: array{name: string, count: int}|null}
     */
    #[Computed]
    public function summary(): array
    {
        $todayStart = CarbonImmutable::today();

        $today = Activity::query()->where('created_at', '>=', $todayStart)->count();
        $week = Activity::query()->where('created_at', '>=', $todayStart->subDays(6))->count();

        $busiestModule = Activity::query()
            ->where('created_at', '>=', $todayStart)
            ->selectRaw('module, count(*) as total')
            ->groupBy('module')
            ->orderByDesc('total')
            ->first();

        $busiestActor = Activity::query()
            ->where('created_at', '>=', $todayStart)
            ->whereNotNull('actor_id')
            ->selectRaw('actor_id, count(*) as total')
            ->groupBy('actor_id')
            ->orderByDesc('total')
            ->first();

        $actorName = $busiestActor === null ? null : User::query()->find($busiestActor->actor_id)?->name;

        return [
            'today' => $today,
            'week' => $week,
            'module' => $busiestModule === null ? null : [
                'label' => app(NotificationPresenter::class)->moduleLabel($busiestModule->module),
                'count' => (int) $busiestModule->total,
            ],
            'actor' => $actorName === null ? null : [
                'name' => $actorName,
                'count' => (int) $busiestActor->total,
            ],
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
    public function moduleTone(string $module): string
    {
        return match ($module) {
            'donors', 'proposals', 'funding-agreements', 'transfers', 'accounts' => 'funding',
            'risks' => 'warn',
            default => 'neutral',
        };
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
    $notificationPresenter = app(NotificationPresenter::class);
    $links = app(SubjectLinks::class);

    $tagClasses = fn (string $tone): string => match ($tone) {
        'funding' => 'border-funding/40 text-funding',
        'warn' => 'border-status-warn/40 text-status-warn',
        default => 'border-secondary-light text-secondary-medium dark:text-secondary-soft',
    };
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="activity-log-heading">
    <x-ui.page-header heading-id="activity-log-heading" :title="__('activity.log_title')" :count="$this->activities->total()">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('activity.filters.search')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('activity.filters.module')" wire:model.live="module" :value="$module">
                <flux:select.option value="">{{ __('activity.filters.all_modules') }}</flux:select.option>
                @foreach (array_keys(config('notifications.modules')) as $option)
                    <flux:select.option :value="$option">{{ $notificationPresenter->moduleLabel($option) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('activity.filters.actor')" wire:model.live="actor" :value="$actor" searchable>
                <flux:select.option value="">{{ __('activity.filters.all_actors') }}</flux:select.option>
                @foreach ($this->actorOptions as $person)
                    <flux:select.option :value="(string) $person->id" :wire:key="'activity-actor-'.$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.input type="date" :label="__('activity.filters.from')" wire:model.live="from" />
            <x-ui.input type="date" :label="__('activity.filters.to')" wire:model.live="to" />
        </x-slot:filters>
    </x-ui.page-header>

    {{-- The statement summary: today's running totals, read calmly before the ledger itself,
         independent of whatever the entries below are filtered to. --}}
    @php
        $summary = $this->summary;
    @endphp
    <div
        role="group"
        aria-label="{{ __('activity.pulse.heading') }}"
        class="grid grid-cols-2 divide-y divide-board-line rounded-xl border border-board-line bg-board @2xl/directory:grid-cols-4 @2xl/directory:divide-x @2xl/directory:divide-y-0 dark:shadow-none"
    >
        <div class="p-3.5">
            <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('activity.pulse.today') }}</p>
            <p class="type-heading mt-1 tabular-nums">{{ $summary['today'] }}</p>
        </div>

        <div class="p-3.5">
            <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('activity.pulse.week') }}</p>
            <p class="type-heading mt-1 tabular-nums">{{ $summary['week'] }}</p>
        </div>

        <div class="p-3.5">
            <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('activity.pulse.busiest_module') }}</p>
            @if ($summary['module'] !== null)
                <p class="type-heading mt-1 flex items-baseline gap-1.5">
                    <span class="truncate">{{ $summary['module']['label'] }}</span>
                    <span class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-secondary-soft">({{ $summary['module']['count'] }})</span>
                </p>
            @else
                <p class="type-heading mt-1 text-secondary-medium dark:text-secondary-soft">{{ __('activity.pulse.quiet') }}</p>
            @endif
        </div>

        <div class="p-3.5">
            <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('activity.pulse.top_actor') }}</p>
            @if ($summary['actor'] !== null)
                <p class="type-heading mt-1 flex items-baseline gap-1.5">
                    <span class="truncate">{{ $summary['actor']['name'] }}</span>
                    <span class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-secondary-soft">({{ $summary['actor']['count'] }})</span>
                </p>
            @else
                <p class="type-heading mt-1 text-secondary-medium dark:text-secondary-soft">{{ __('activity.pulse.quiet') }}</p>
            @endif
        </div>
    </div>

    @if ($this->activities->isEmpty())
        <x-ui.empty-state icon="document-text" :heading="__('activity.empty')" />
    @else
        @php
            $groupedByDay = $sortField === 'created_at';
            $days = $groupedByDay
                ? collect($this->activities->items())->groupBy(fn ($activity) => $activity->created_at?->toDateString())
                : collect([null => collect($this->activities->items())]);
            $today = CarbonImmutable::today()->toDateString();
            $yesterday = CarbonImmutable::yesterday()->toDateString();
        @endphp

        <div class="flex flex-col gap-4" wire:loading.class="opacity-50" wire:target="module, actor, search, from, to, setSort, previousPage, nextPage, gotoPage">
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
                                        <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 text-secondary-medium rtl:-scale-x-100 dark:text-secondary-soft" x-bind:class="open && 'rotate-90 rtl:rotate-90'" style="transition: transform 150ms" />
                                        <span class="type-label truncate text-secondary-dark dark:text-white">{{ $dayLabel }}</span>
                                    </span>
                                    <span class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-secondary-soft">{{ trans_choice('activity.ledger.entry_count', $dayActivities->count(), ['count' => $dayActivities->count()]) }}</span>
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
                                    <a href="{{ route('programs.show', tap(new Program, fn ($program) => $program->id = $activity->program_id)) }}" wire:navigate class="truncate text-secondary-dark hover:text-status-critical hover:underline dark:text-white">
                                        {{ $programName }}
                                    </a>
                                @else
                                    <span class="text-secondary-light dark:text-secondary-medium">—</span>
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell class="@3xl:text-end">
                                @if ($url !== null)
                                    <a href="{{ $url }}" wire:navigate class="font-semibold text-status-critical hover:underline">{{ __('activity.open_record') }}</a>
                                @elseif (! $exists)
                                    <span class="text-secondary-medium dark:text-secondary-soft">{{ __('activity.record_gone') }}</span>
                                @endif
                            </x-ui.data-table.cell>
                        </tr>
                    @endforeach
                </x-ui.data-table>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->activities" />
    @endif
</section>
