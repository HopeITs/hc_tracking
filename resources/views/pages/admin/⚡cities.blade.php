<?php

use App\Models\HrCity;
use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.city_directory')] class extends Component {
    use WithPagination;

    private const PerPage = 12;

    /**
     * Each governorate's approximate position on the coverage map's real
     * Syria silhouette (see {@see self::MapOutlinePath}), in that outline's
     * own 1024×1024 coordinate space — read off its traced borders city by
     * city, not surveyed coordinates. Keyed by the untranslated `name_en`
     * column, since {@see HrCity::name} switches to Arabic under the Arabic
     * locale. "Syria" (nationwide, tied to no single governorate) is
     * deliberately absent; any HR city with no entry here — that one
     * included — falls back to the map's "not shown" tray instead of
     * guessing a position.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const MapPositions = [
        'Latakia' => [185, 172],
        'Idleb' => [235, 210],
        'Aleppo' => [410, 150],
        'Ar-raqqa' => [600, 190],
        'Al-Hasakah' => [795, 155],
        'Deir Az-zor' => [660, 340],
        'Tartus' => [95, 290],
        'Hama' => [290, 290],
        'Homs' => [370, 400],
        'Homs Rural' => [480, 430],
        'Rif Dimashq' => [390, 570],
        'Damascus' => [350, 620],
        'Qounaitra' => [190, 645],
        'Daraa' => [270, 780],
        'Suwaida' => [420, 760],
    ];

    /**
     * Syria's national outline, traced to a 1024×1024 canvas — a real
     * silhouette (not a schematic shape), since every governorate on this
     * map sits inside Syria. Free collection, credit required, no resale:
     * "Mapsicon" by Damien Erambert (github.com/djaiss/mapsicon), country
     * code `sy`, used verbatim including its own transform.
     */
    public const MapOutlinePath = <<<'SVG'
        <g transform="translate(0.000000,1024.000000) scale(0.100000,-0.100000)" fill="currentColor" stroke="none">
        <path d="M9935 9779 c-22 -22 -83 -78 -136 -124 -87 -76 -101 -85 -165 -101
        -38 -9 -91 -29 -119 -44 -56 -30 -175 -60 -242 -60 -43 0 -91 -19 -163 -65
        -47 -31 -177 -45 -395 -46 -142 0 -182 3 -242 20 -56 16 -103 21 -200 21 -71
        1 -175 5 -233 10 -153 12 -206 1 -303 -68 -89 -62 -197 -111 -244 -112 -42 0
        -214 -91 -368 -194 -108 -71 -127 -81 -190 -91 -63 -10 -81 -18 -178 -85 -59
        -41 -129 -87 -156 -102 -53 -31 -167 -58 -240 -58 -27 0 -66 -7 -87 -16 -58
        -25 -131 -43 -209 -54 -38 -5 -111 -18 -160 -28 -50 -11 -124 -23 -165 -26
        -41 -4 -100 -12 -130 -18 -50 -9 -60 -7 -91 11 -59 35 -168 43 -414 33 -285
        -12 -282 -13 -323 49 -27 41 -61 68 -175 143 -191 125 -220 141 -302 164 -175
        50 -285 30 -445 -80 -149 -102 -215 -134 -309 -152 -46 -9 -103 -16 -128 -16
        -72 0 -256 -72 -398 -155 -79 -47 -141 -65 -180 -53 -62 19 -194 32 -300 30
        -93 -2 -114 -5 -137 -22 -36 -26 -46 -25 -80 6 -24 24 -26 32 -21 69 4 30 0
        52 -14 80 -19 37 -24 40 -189 97 -202 71 -283 86 -320 61 -36 -26 -104 -164
        -104 -211 0 -20 -7 -48 -16 -61 -12 -17 -17 -48 -18 -115 -1 -85 -3 -96 -28
        -129 -26 -33 -28 -43 -28 -128 l0 -93 42 -43 c36 -36 40 -46 32 -65 -16 -37
        -6 -58 26 -58 16 0 42 -11 59 -26 28 -23 31 -32 31 -80 0 -71 -13 -78 -134
        -70 -82 6 -99 4 -165 -18 -41 -15 -78 -26 -83 -26 -5 0 -14 14 -19 30 -8 21
        -17 30 -33 30 -33 0 -37 -9 -21 -47 18 -44 18 -43 -1 -43 -13 0 -15 -8 -10
        -37 3 -21 8 -77 11 -126 7 -84 6 -89 -21 -132 -25 -39 -33 -44 -68 -47 -38 -3
        -41 -6 -49 -42 l-10 -38 -44 6 c-86 12 -112 -23 -129 -170 -10 -84 -40 -101
        -66 -36 -14 35 -19 39 -60 45 -80 11 -96 23 -103 79 l-7 50 -60 -6 c-87 -10
        -126 -32 -134 -74 -5 -23 -14 -37 -33 -45 -14 -6 -37 -19 -50 -29 -13 -10 -33
        -18 -43 -18 -15 0 -18 -4 -13 -17 15 -40 35 -141 35 -179 0 -47 -31 -83 -93
        -109 -34 -14 -36 -18 -26 -37 15 -29 5 -95 -19 -121 -11 -12 -23 -34 -27 -49
        -6 -22 -3 -27 17 -33 26 -9 209 -175 235 -214 15 -22 17 -47 14 -166 -2 -136
        -2 -141 24 -188 25 -45 26 -51 15 -95 -6 -25 -27 -80 -46 -121 -24 -53 -34
        -88 -34 -125 0 -54 -10 -100 -31 -154 -12 -28 -11 -43 9 -120 12 -48 22 -102
        22 -120 0 -28 21 -108 83 -316 22 -71 43 -79 245 -88 97 -4 194 -11 214 -16
        36 -9 39 -8 59 24 l20 34 37 -16 c20 -8 52 -24 70 -35 18 -10 42 -19 52 -19
        32 0 50 -11 50 -31 0 -14 -7 -19 -26 -19 -40 0 -64 -18 -64 -47 0 -17 -10 -35
        -25 -48 -13 -11 -26 -33 -27 -48 -3 -28 0 -30 63 -46 69 -17 122 -52 179 -118
        30 -33 32 -40 27 -86 -7 -58 24 -147 52 -147 9 0 22 -4 30 -9 12 -8 12 -13 -2
        -35 -21 -31 -22 -68 -3 -104 25 -48 23 -71 -4 -85 -18 -9 -26 -22 -28 -45 -3
        -25 -12 -39 -43 -59 -34 -22 -39 -31 -39 -63 0 -34 -4 -39 -42 -56 -27 -12
        -58 -18 -85 -16 l-43 4 0 -35 c0 -31 -8 -42 -67 -93 -38 -32 -85 -73 -105 -92
        l-36 -35 16 -33 c14 -31 19 -34 62 -34 36 0 52 -5 69 -24 21 -23 22 -25 7 -55
        -28 -53 -46 -55 -105 -11 l-52 39 -102 -6 c-80 -4 -106 -10 -123 -24 -12 -12
        -36 -19 -60 -20 -36 0 -44 -6 -94 -67 -82 -98 -109 -138 -116 -169 -4 -15 -15
        -35 -26 -44 -29 -23 -16 -63 18 -56 20 3 28 -2 40 -24 8 -17 23 -29 34 -29 11
        0 34 -14 52 -30 l33 -30 -28 -25 c-15 -14 -39 -25 -53 -25 -14 0 -43 -9 -64
        -20 -37 -19 -40 -23 -40 -64 0 -36 -7 -52 -42 -95 -25 -31 -50 -51 -62 -51
        -45 0 -62 -13 -91 -67 -27 -51 -36 -59 -102 -90 -40 -18 -77 -42 -82 -52 -6
        -10 -25 -24 -43 -30 -54 -20 -118 -53 -118 -62 0 -14 38 -20 76 -13 l37 7 -7
        -34 c-4 -19 -10 -56 -13 -84 -3 -27 -16 -81 -29 -118 -14 -40 -24 -92 -24
        -125 -1 -31 -7 -68 -15 -82 -10 -18 -14 -61 -15 -156 0 -162 12 -229 47 -266
        36 -38 29 -93 -22 -149 -20 -23 -35 -42 -33 -44 2 -2 19 -5 39 -6 27 -2 51 8
        113 49 88 58 108 60 192 21 22 -10 59 -19 81 -19 32 0 44 -5 57 -25 12 -19 26
        -25 54 -26 l37 -1 -30 -10 c-39 -14 -38 -13 -30 -37 5 -18 15 -21 55 -21 l49
        0 6 -47 c3 -27 19 -75 35 -108 16 -33 31 -69 32 -79 3 -17 11 -19 73 -17 39 1
        81 5 93 8 19 4 35 -6 80 -49 32 -30 80 -69 109 -87 29 -18 75 -61 103 -95 51
        -63 52 -63 112 -70 34 -3 67 -11 74 -17 7 -6 77 -18 156 -26 79 -9 150 -21
        158 -28 8 -7 33 -13 55 -14 22 0 73 -9 113 -20 l74 -19 15 27 c11 18 258 182
        767 508 877 561 2581 1591 4473 2703 l1182 695 57 135 c65 153 163 352 237
        484 l50 90 -27 370 c-14 204 -26 394 -26 422 0 35 10 74 34 130 32 73 35 90
        45 243 12 181 5 160 110 331 l52 85 -3 180 c-3 178 -3 181 -35 255 -18 42 -66
        133 -107 203 l-75 127 16 283 15 282 87 168 c81 157 88 168 121 177 19 5 164
        41 322 81 l288 71 375 429 c354 404 403 469 360 469 -18 0 -18 -3 -4 59 10 46
        9 54 -11 82 -21 28 -22 35 -11 87 l11 57 -47 42 c-37 31 -59 43 -95 47 -46 6
        -47 7 -41 36 5 28 3 30 -24 30 -21 0 -40 -11 -68 -41z"/>
        </g>
        SVG;

    /**
     * The governorate the connection lines on the map radiate from.
     */
    private const HeadquartersCity = 'Damascus';

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

    /**
     * 'map' or 'grid' — which body the page renders below the summary strip.
     */
    #[Url(except: 'map')]
    public string $view = 'map';

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

    /**
     * Every HR city, unpaginated — the coverage map plots the whole
     * portfolio at once rather than one page of results, since removing
     * cities from view when the list scrolls would break the geography.
     *
     * @return Collection<int, HrCity>
     */
    #[Computed]
    public function allCities(): Collection
    {
        return HrCity::query()
            ->select(['id', 'name_en', 'name_ar', 'is_active'])
            ->orderBy(HrCity::nameColumn())
            ->orderBy('id')
            ->get();
    }

    /**
     * Every city's full delivery picture, ready to hand to the map's Alpine
     * component as one JSON blob — every label pre-translated and every
     * count pre-pluralised server-side, so the client only ever swaps which
     * city's already-correct strings are on screen.
     *
     * Cities the search or status filter would exclude stay on the map
     * (removing one would leave a hole in the geography) but are flagged
     * `matches: false` so the template can dim them instead.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function mapCities(): array
    {
        $stats = $this->projectStatsByCity;
        $search = mb_strtolower(trim($this->search));

        return $this->allCities
            ->map(function (HrCity $city) use ($stats, $search): array {
                $name = trim($city->name_en);
                $position = self::MapPositions[$name] ?? null;
                $cityStats = $stats->get($city->id, ['total' => 0, 'open' => 0, 'closed' => 0, 'programs' => 0, 'beneficiaries' => 0]);

                $matchesSearch = $search === ''
                    || str_contains(mb_strtolower($city->name_en), $search)
                    || str_contains(mb_strtolower($city->name_ar), $search);
                $matchesStatus = $this->status === '' || ($this->status === 'active') === $city->is_active;

                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'secondaryName' => $city->secondary_name,
                    'isActive' => $city->is_active,
                    'statusLabel' => $city->is_active ? __('common.active') : __('cities.inactive'),
                    'nationwide' => $name === 'Syria',
                    'x' => $position[0] ?? null,
                    'y' => $position[1] ?? null,
                    'isHq' => $name === self::HeadquartersCity,
                    'total' => $cityStats['total'],
                    'open' => $cityStats['open'],
                    'closed' => $cityStats['closed'],
                    'openPct' => $cityStats['total'] > 0 ? (int) round(($cityStats['open'] / $cityStats['total']) * 100) : 0,
                    'projectsLabel' => trans_choice('common.count_project', $cityStats['total'], ['count' => $cityStats['total']]),
                    'programsLabel' => $cityStats['total'] > 0
                        ? trans_choice('cities.count_program', $cityStats['programs'], ['count' => $cityStats['programs']])
                        : null,
                    'beneficiariesLine' => $cityStats['beneficiaries'] > 0
                        ? Number::format($cityStats['beneficiaries']).' '.__('cities.beneficiaries_reached')
                        : null,
                    'noProjectsLabel' => __('cities.no_projects_here_yet'),
                    'href' => route('projects.index', ['city' => $city->id]),
                    'matches' => $matchesSearch && $matchesStatus,
                ];
            })
            ->values()
            ->all();
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

    $mapCities = collect($this->mapCities);
    $mappedCities = $mapCities->filter(fn (array $city): bool => $city['x'] !== null)->values();
    $unmappedCities = $mapCities->reject(fn (array $city): bool => $city['x'] !== null)->values();
    $hqCity = $mappedCities->firstWhere('isHq', true);
    $defaultSelectedCityId = optional($mappedCities->sortByDesc('total')->first())['id'] ?? optional($mappedCities->first())['id'] ?? null;
    $matchingMapCount = $mapCities->where('matches', true)->count();
    $mapFiltersActive = $search !== '' || $status !== '';
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
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.view-toggle
                    wire-model="view"
                    :active="$view"
                    :options="[
                        ['key' => 'map', 'label' => __('cities.view_map'), 'icon' => 'map'],
                        ['key' => 'grid', 'label' => __('cities.view_grid'), 'icon' => 'squares-2x2'],
                    ]"
                    :label="__('cities.view_as')"
                />

                <x-ui.view-toggle
                    wire-model="status"
                    :active="$status"
                    :options="$statusOptions"
                    :label="__('cities.status')"
                />
            </div>
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

    @if ($view === 'map')
        @include('pages.admin._cities-map', [
            'mappedCities' => $mappedCities,
            'unmappedCities' => $unmappedCities,
            'hqCity' => $hqCity,
            'defaultSelectedCityId' => $defaultSelectedCityId,
            'matchingMapCount' => $matchingMapCount,
            'mapFiltersActive' => $mapFiltersActive,
            'avatarTones' => $avatarTones,
            'mapOutlinePath' => $this::MapOutlinePath,
        ])
    @else
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
    @endif
</section>
