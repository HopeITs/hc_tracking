<?php

use App\Models\HrCity;
use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.city_directory')] class extends Component {
    use WithPagination;

    private const PerPage = 12;

    #[Url(except: '')]
    public string $search = '';

    /**
     * '' (all), 'active' or 'inactive' — filters on HrCity::is_active.
     */
    #[Url(except: '')]
    public string $status = '';

    /**
     * 'name', 'projects' or 'active' — see {@see self::cities()}.
     */
    #[Url(except: 'name')]
    public string $sort = 'name';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Cities come from the HR database and are read-only here; every city is
     * listed, active or not, exactly as HR holds it.
     *
     * Sorting by name stays a plain database query. Sorting by delivery
     * signal (project count, active-first) needs {@see self::projectStatsByCity()}
     * for every matching city up front — cities live in a different database
     * connection from projects, so that can never be a single ordered query —
     * which means those two modes fetch every matching city once and paginate
     * the sorted collection in PHP instead of in SQL. HR city lists are small
     * reference tables (dozens, not thousands), so this stays cheap.
     *
     * @return LengthAwarePaginator<int, HrCity>
     */
    #[Computed]
    public function cities(): LengthAwarePaginator
    {
        $search = trim($this->search);

        $query = HrCity::query()
            ->select(['id', 'name_en', 'name_ar', 'is_active'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name_en', 'like', '%'.$search.'%')
                ->orWhere('name_ar', 'like', '%'.$search.'%')))
            ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'));

        if ($this->sort === 'name') {
            return $query
                ->orderBy(HrCity::nameColumn())
                ->orderBy('id')
                ->paginate(self::PerPage);
        }

        $stats = $this->projectStatsByCity;
        $page = (int) $this->getPage();

        $sorted = $query->get()
            ->sort(fn (HrCity $a, HrCity $b): int => match ($this->sort) {
                'projects' => ($stats->get($b->id)['total'] ?? 0) <=> ($stats->get($a->id)['total'] ?? 0),
                'active' => (int) $b->is_active <=> (int) $a->is_active,
                default => 0,
            } ?: strcmp($a->name, $b->name))
            ->values();

        return new LengthAwarePaginator(
            $sorted->forPage($page, self::PerPage)->values(),
            $sorted->count(),
            self::PerPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * Delivery signal for every HR city that has at least one project, keyed
     * by city id: how many projects, how many are still open vs closed, how
     * many distinct programs are behind them, and the beneficiaries targeted.
     *
     * Scoped to the whole portfolio rather than just the visible page — the
     * summary strip and the "most projects first" sort both need every city's
     * figures, not only the 12 on screen.
     *
     * @return Collection<int, array{total: int, open: int, closed: int, programs: int, beneficiaries: int}>
     */
    #[Computed]
    public function projectStatsByCity(): Collection
    {
        return Project::query()
            ->select(['city_id', 'program_id', 'status', 'target_beneficiaries'])
            ->get()
            ->groupBy('city_id')
            ->map(function (Collection $projects): array {
                $open = $projects->filter(fn (Project $project): bool => $project->status->isOpen())->count();

                return [
                    'total' => $projects->count(),
                    'open' => $open,
                    'closed' => $projects->count() - $open,
                    'programs' => $projects->pluck('program_id')->unique()->count(),
                    'beneficiaries' => (int) $projects->sum('target_beneficiaries'),
                ];
            });
    }

    /**
     * Portfolio-wide figures for the summary strip — independent of the
     * current search, filter or page.
     *
     * @return array{totalCities: int, activeCities: int, coveredCities: int, openProjects: int, beneficiaries: int}
     */
    #[Computed]
    public function summary(): array
    {
        $stats = $this->projectStatsByCity;

        return [
            'totalCities' => HrCity::count(),
            'activeCities' => HrCity::where('is_active', true)->count(),
            'coveredCities' => $stats->count(),
            'openProjects' => (int) $stats->sum('open'),
            'beneficiaries' => (int) $stats->sum('beneficiaries'),
        ];
    }
};
?>

@php
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
    $summary = $this->summary;
    $stats = $this->projectStatsByCity;

    $figures = [
        ['key' => 'covered', 'label' => __('cities.cities_covered'), 'value' => $summary['coveredCities'], 'suffix' => '', 'sub' => __('cities.cities_covered_hint', ['total' => $summary['totalCities']]), 'tone' => 'text-funding'],
        ['key' => 'active', 'label' => __('cities.active_cities'), 'value' => $summary['activeCities'], 'suffix' => '', 'sub' => __('cities.cities_covered_hint', ['total' => $summary['totalCities']]), 'tone' => 'text-status-good'],
        ['key' => 'open', 'label' => __('cities.open_projects'), 'value' => $summary['openProjects'], 'suffix' => '', 'sub' => __('cities.open_projects_hint'), 'tone' => ''],
        ['key' => 'reached', 'label' => __('cities.beneficiaries_reached'), 'value' => $summary['beneficiaries'], 'suffix' => '', 'sub' => __('cities.beneficiaries_reached_hint'), 'tone' => 'text-primary'],
    ];

    // A calm rotation of already-brand-safe soft tones so the grid reads as
    // lively rather than sixteen identical grey tiles — never the primary
    // red, which the rest of the app reserves for late/critical signal.
    $avatarTones = [
        'bg-funding-soft text-funding',
        'bg-status-active-soft text-status-active',
        'bg-status-good-soft text-status-good',
        'bg-status-warn-soft text-status-warn',
        'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    ];

    $sortOptions = [
        'name' => __('cities.sort_name'),
        'projects' => __('cities.sort_most_projects'),
        'active' => __('cities.sort_active_first'),
    ];

    $statusOptions = [
        ['key' => '', 'label' => __('cities.all_cities')],
        ['key' => 'active', 'label' => __('common.active')],
        ['key' => 'inactive', 'label' => __('cities.inactive')],
    ];
@endphp

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="city-directory-heading"
>
    <x-ui.page-header heading-id="city-directory-heading" :title="__('common.cities')" :count="$this->cities->total()">
        <x-slot:filters>
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('cities.search_cities')"
                :placeholder="__('cities.city_name')"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <x-ui.header-filter-select :label="__('cities.sort_by')" wire:model.live="sort" :value="$sort">
                @foreach ($sortOptions as $optionValue => $optionLabel)
                    <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.view-toggle
                wire-model="status"
                :active="$status"
                :options="$statusOptions"
                :label="__('cities.status')"
            />
        </x-slot:actions>

        <x-slot:summary>
            {{-- The portfolio's figures, set directly on the page rather than boxed into cards: the numbers are the point (same treatment as reports/⚡index.blade.php). --}}
            <div class="grid grid-cols-2 gap-x-6 gap-y-6 border-y border-secondary-soft py-6 @xl/directory:grid-cols-4 @xl/directory:divide-x @xl/directory:divide-secondary-soft dark:border-secondary-medium @xl/directory:dark:divide-secondary-medium">
                @foreach ($figures as $index => $figure)
                    <div
                        class="logframe-reveal @xl/directory:ps-6 @xl/directory:first:ps-0"
                        style="--logframe-order: {{ $index }}"
                        wire:key="figure-{{ $figure['key'] }}"
                    >
                        <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $figure['label'] }}</p>
                        <p
                            class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums {{ $figure['tone'] }}"
                            x-data="countUp({{ (int) $figure['value'] }})"
                            x-text="display + '{{ $figure['suffix'] }}'"
                        >{{ $figure['value'] }}{{ $figure['suffix'] }}</p>
                        <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ $figure['sub'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-slot:summary>
    </x-ui.page-header>

    <div class="flex min-h-8 flex-wrap items-center justify-between gap-2">
        <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
            {{ trans_choice('cities.count_matching_cities', $this->cities->total(), ['count' => $this->cities->total()]) }}
        </p>

        <div class="flex items-center gap-2">
            <span wire:loading.delay.flex wire:target="search,status,sort,clearSearch,gotoPage,previousPage,nextPage" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                <flux:icon.arrow-path class="size-3.5 animate-spin" />
                {{ __('common.updating') }}
            </span>

            @if ($search !== '')
                <flux:button
                    size="sm"
                    variant="ghost"
                    class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!"
                    wire:click="clearSearch"
                >
                    {{ __('cities.clear_search') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if ($this->cities->isEmpty())
        <x-ui.empty-state
            icon="map-pin"
            :heading="__('cities.no_matching_cities')"
            :body="__('common.change_the_search_and_try_again')"
            class="rounded-xl border border-secondary-soft dark:border-secondary-medium"
        >
            <x-slot:action>
                <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearSearch">
                    {{ __('cities.clear_search') }}
                </flux:button>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        <div class="grid grid-cols-1 gap-4 @sm/directory:grid-cols-2 @4xl/directory:grid-cols-3 @7xl/directory:grid-cols-4">
            @foreach ($this->cities as $index => $city)
                @php
                    $cityStats = $stats->get($city->id, ['total' => 0, 'open' => 0, 'closed' => 0, 'programs' => 0, 'beneficiaries' => 0]);
                    $openPct = $cityStats['total'] > 0 ? ($cityStats['open'] / $cityStats['total']) * 100 : 0;
                    $avatarTone = $avatarTones[$city->id % count($avatarTones)];
                @endphp
                <a
                    href="{{ route('projects.index', ['city' => $city->id]) }}"
                    wire:navigate
                    wire:key="city-{{ $city->id }}"
                    aria-label="{{ __('cities.view_projects_in_name', ['name' => $city->name]) }}"
                    class="logframe-reveal group relative flex flex-col gap-4 overflow-hidden rounded-xl border border-secondary-soft bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] transition-all hover:-translate-y-0.5 hover:border-secondary-light hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none dark:hover:shadow-none"
                    style="--logframe-order: {{ min($index, 11) }}"
                >
                    <span
                        aria-hidden="true"
                        class="{{ $city->is_active ? 'bg-status-good' : 'bg-secondary-light dark:bg-secondary-medium' }} absolute inset-x-0 top-0 h-1"
                    ></span>

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="{{ $avatarTone }} flex size-11 shrink-0 items-center justify-center rounded-xl">
                                <flux:icon.map-pin class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-base font-bold text-secondary-dark dark:text-white">{{ $city->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft" dir="auto">{{ $city->secondary_name }}</p>
                            </div>
                        </div>

                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $city->is_active,
                            'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft' => ! $city->is_active,
                        ])>
                            {{ $city->is_active ? __('common.active') : __('cities.inactive') }}
                        </span>
                    </div>

                    <div class="flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <p class="type-figure text-secondary-dark dark:text-white">{{ $cityStats['total'] }}</p>
                            <p class="type-meta text-secondary-medium dark:text-secondary-soft">
                                {{ trans_choice('common.count_project', $cityStats['total'], ['count' => $cityStats['total']]) }}
                            </p>
                        </div>

                        @if ($cityStats['total'] > 0)
                            <div class="min-w-0 text-end">
                                <p class="type-meta truncate text-secondary-medium dark:text-secondary-soft">
                                    {{ trans_choice('cities.count_program', $cityStats['programs'], ['count' => $cityStats['programs']]) }}
                                </p>
                                @if ($cityStats['beneficiaries'] > 0)
                                    <p class="type-meta truncate text-secondary-medium dark:text-secondary-soft">
                                        {{ \Illuminate\Support\Number::format($cityStats['beneficiaries']) }} {{ __('cities.beneficiaries_reached') }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($cityStats['total'] > 0)
                        <div>
                            <div class="flex h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="presentation">
                                <div class="h-full bg-status-active" style="width: {{ $openPct }}%"></div>
                                <div class="h-full bg-secondary-light dark:bg-secondary-light/60" style="width: {{ 100 - $openPct }}%"></div>
                            </div>
                            <div class="mt-1 flex items-center justify-between text-[11px] text-secondary-medium dark:text-secondary-soft">
                                <span>{{ $cityStats['open'] }} {{ __('cities.open') }}</span>
                                <span>{{ $cityStats['closed'] }} {{ __('cities.closed') }}</span>
                            </div>
                        </div>
                    @else
                        <p class="type-meta text-secondary-light italic dark:text-secondary-medium">{{ __('cities.no_projects_here_yet') }}</p>
                    @endif

                    <span class="mt-auto flex items-center gap-1 text-xs font-semibold text-secondary-medium transition-colors group-hover:text-primary dark:text-secondary-soft dark:group-hover:text-primary">
                        {{ __('common.projects') }}
                        <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                    </span>
                </a>
            @endforeach
        </div>

        <flux:pagination
            :paginator="$this->cities"
            class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
            scroll-to="#city-directory-heading"
        />
    @endif
</section>
