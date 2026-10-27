{{--
    The coverage map: Syria's real governorate boundaries (see
    HrCity::MapOutlinePath), with every city plotted at its governorate's real
    geometric centroid, sized and coloured by delivery signal, and a
    click-to-select detail panel beside it. Every city's detail panel is
    fully server-rendered — with a real "View projects" link — and Alpine
    only ever toggles which one is visible, so the page carries real content
    and working links even before JavaScript runs.

    @var \Illuminate\Support\Collection $mappedCities
    @var \Illuminate\Support\Collection $unmappedCities
    @var array|null $hqCity
    @var int|null $defaultSelectedCityId
    @var int $matchingMapCount
    @var bool $mapFiltersActive
    @var array<int, string> $avatarTones
    @var string $mapOutlinePath
    @var bool $canManageMealCoverage
--}}

@if ($mapFiltersActive)
    <p class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">
        {{ trans_choice('cities.count_matching_cities', $matchingMapCount, ['count' => $matchingMapCount]) }}
    </p>
@endif

<div
    class="grid grid-cols-1 items-start gap-4 @3xl/directory:grid-cols-[minmax(0,1fr)_300px]"
    x-data="{ selected: {{ \Illuminate\Support\Js::from($defaultSelectedCityId) }} }"
>
    <x-ui.panel :title="__('cities.coverage_map')" :subtitle="__('cities.tap_a_city_hint')">
        <div class="bg-board-head p-4 pt-16">
            <div class="relative mx-auto w-full max-w-xl" style="aspect-ratio: 670.92236 / 590.26318;">
                {{-- Syria's real governorate boundaries — see HrCity::MapOutlinePath for source and licence. Each governorate strokes with the wrapper's own background colour, so borders read as a gap rather than a drawn line. --}}
                <svg viewBox="0 0 670.92236 590.26318" class="absolute inset-0 h-full w-full text-secondary-soft [&_path]:stroke-[var(--color-board-head)] dark:text-line-strong" aria-hidden="true" preserveAspectRatio="xMidYMid meet" fill="currentColor" stroke-width="1.3">
                    {!! $mapOutlinePath !!}

                    {{-- Delivery lines fan out from HQ to every city currently running a project — the literal "connections" in the data. --}}
                    @if ($hqCity !== null)
                        @foreach ($mappedCities as $lineCity)
                            @continue($lineCity['isHq'] || $lineCity['total'] === 0)
                            <line
                                x1="{{ $hqCity['x'] }}"
                                y1="{{ $hqCity['y'] }}"
                                x2="{{ $lineCity['x'] }}"
                                y2="{{ $lineCity['y'] }}"
                                stroke="var(--color-status-active)"
                                stroke-width="2"
                                stroke-dasharray="2 6"
                                stroke-linecap="round"
                                opacity="{{ $lineCity['matches'] ? '0.55' : '0.15' }}"
                            />
                        @endforeach
                    @endif
                </svg>

                @foreach ($mappedCities as $city)
                    @php
                        $markerSize = max(16, min(36, 16 + $city['total'] * 5));
                        $leftPct = $city['x'] / 670.92236 * 100;
                        $topPct = $city['y'] / 590.26318 * 100;
                        $avatarTone = $avatarTones[$city['id'] % count($avatarTones)];
                    @endphp
                    <button
                        type="button"
                        wire:key="marker-{{ $city['id'] }}"
                        x-on:click="selected = {{ $city['id'] }}"
                        :aria-pressed="selected === {{ $city['id'] }} ? 'true' : 'false'"
                        aria-label="{{ __('cities.view_projects_in_name', ['name' => $city['name']]) }}"
                        class="group absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full p-2 hover:z-30 focus-visible:z-30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium"
                        style="left: {{ $leftPct }}%; top: {{ $topPct }}%;"
                        :class="selected === {{ $city['id'] }} ? 'z-10' : 'z-0'"
                    >
                        {{-- Hover/focus tooltip: the same governorate story the click-to-select panel shows, glanceable without committing to a click. --}}
                        <span
                            role="tooltip"
                            class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 flex -translate-x-1/2 flex-col items-center opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100"
                        >
                            <span class="rounded-lg bg-secondary-dark px-3 py-1.5 text-start whitespace-nowrap shadow-lg dark:bg-surface-3 dark:shadow-raised">
                                <span class="block text-xs font-bold text-white dark:text-ink">{{ $city['name'] }}</span>
                                <span class="block text-[11px] text-secondary-soft dark:text-ink-muted" dir="auto">{{ $city['secondaryName'] }}</span>
                                <span class="block text-[11px] text-secondary-soft dark:text-ink-muted">{{ $city['statusLabel'] }} · {{ $city['projectsLabel'] }}</span>
                            </span>
                            <span class="-mt-1 size-2 rotate-45 bg-secondary-dark dark:bg-surface-3"></span>
                        </span>

                        <span
                            :class="selected === {{ $city['id'] }} ? 'ring-2 ring-primary ring-offset-2 dark:ring-offset-surface-1' : ''"
                            class="{{ $avatarTone }} {{ $city['isActive'] ? 'border-status-good' : 'border-secondary-light dark:border-ink-faint' }} {{ $city['matches'] ? '' : 'opacity-30' }} flex shrink-0 items-center justify-center rounded-full border-2 transition-all duration-150 hover:scale-110"
                            style="width: {{ $markerSize }}px; height: {{ $markerSize }}px;"
                        >
                            <flux:icon :name="$city['isHq'] ? 'building-office' : 'map-pin'" class="size-1/2" />
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        <x-slot:footer>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-secondary-medium dark:text-ink-muted">
                <span class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-full border-2 border-status-good bg-secondary-soft dark:bg-line-strong"></span>
                    {{ __('common.active') }}
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-full border-2 border-secondary-light bg-secondary-soft dark:border-ink-faint dark:bg-line-strong"></span>
                    {{ __('cities.inactive') }}
                </span>
                <span class="flex items-center gap-1.5">
                    <flux:icon.building-office variant="micro" class="size-3" />
                    {{ __('cities.headquarters_label') }}
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="size-3.5 rounded-full bg-secondary-soft dark:bg-line-strong"></span>
                    {{ __('cities.marker_size_hint') }}
                </span>
                <span class="ms-auto italic">{{ __('cities.schematic_note') }}</span>
            </div>
        </x-slot:footer>
    </x-ui.panel>

    {{--
        One fully server-rendered detail panel per mapped city, stacked in the
        same slot — Alpine only shows the selected one. Every panel already
        carries its own real "View projects" link and translated copy, so the
        page works (and is testable) before any JavaScript runs; x-cloak keeps
        the non-selected panels from flashing on load.
    --}}
    <div>
        @foreach ($mappedCities as $city)
            <div
                x-show="selected === {{ $city['id'] }}"
                x-cloak
                wire:key="detail-{{ $city['id'] }}"
                class="flex min-h-64 flex-col gap-4 rounded-xl border border-secondary-soft bg-white p-4 dark:border-line dark:bg-surface-1"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-lg font-bold text-secondary-dark dark:text-ink">{{ $city['name'] }}</p>
                        <p class="truncate text-xs text-secondary-medium dark:text-ink-muted" dir="auto">{{ $city['secondaryName'] }}</p>
                    </div>
                    <span @class([
                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                        'bg-status-good-soft text-status-good' => $city['isActive'],
                        'bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted' => ! $city['isActive'],
                    ])>{{ $city['statusLabel'] }}</span>
                </div>

                <div class="flex items-end justify-between gap-3">
                    <div class="min-w-0">
                        <p class="type-figure text-secondary-dark dark:text-ink">{{ $city['total'] }}</p>
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ $city['projectsLabel'] }}</p>
                    </div>
                    @if ($city['total'] > 0)
                        <div class="min-w-0 text-end">
                            <p class="type-meta truncate text-secondary-medium dark:text-ink-muted">{{ $city['programsLabel'] }}</p>
                            @if ($city['beneficiariesLine'] !== null)
                                <p class="type-meta truncate text-secondary-medium dark:text-ink-muted">{{ $city['beneficiariesLine'] }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($city['total'] > 0)
                    <div>
                        <div class="flex h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="presentation">
                            <div class="h-full bg-status-active" style="width: {{ $city['openPct'] }}%"></div>
                            <div class="h-full bg-secondary-light dark:bg-ink-faint/60" style="width: {{ 100 - $city['openPct'] }}%"></div>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-[11px] text-secondary-medium dark:text-ink-muted">
                            <span>{{ $city['open'] }} {{ __('cities.open') }}</span>
                            <span>{{ $city['closed'] }} {{ __('cities.closed') }}</span>
                        </div>
                    </div>
                @else
                    <p class="type-meta text-secondary-light italic dark:text-ink-faint">{{ $city['noProjectsLabel'] }}</p>
                @endif

                <div class="border-t border-secondary-soft pt-3 dark:border-line">
                    @include('pages.admin._cities-meal-officer', [
                        'officer' => $city['officer'],
                        'inherited' => false,
                        'canManage' => $canManageMealCoverage,
                        'scope' => 'city',
                        'targetId' => $city['id'],
                    ])
                </div>

                <a
                    href="{{ $city['href'] }}"
                    wire:navigate
                    class="group mt-auto flex items-center justify-center gap-1.5 rounded-lg border border-secondary-light px-3 py-2 text-sm font-semibold text-secondary-dark transition-colors hover:bg-secondary-soft dark:border-line dark:text-ink dark:hover:bg-surface-2"
                >
                    {{ __('common.projects') }}
                    <flux:icon.chevron-right variant="micro" class="size-3.5 transition-transform group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                </a>
            </div>
        @endforeach
    </div>
</div>

@if ($unmappedCities->isNotEmpty())
    <div class="flex flex-wrap items-center gap-2">
        <p class="type-label shrink-0 text-secondary-medium dark:text-ink-muted">{{ __('cities.not_shown_on_map') }}</p>
        @foreach ($unmappedCities as $city)
            <a
                href="{{ $city['href'] }}"
                wire:navigate
                wire:key="unmapped-{{ $city['id'] }}"
                class="inline-flex items-center gap-1.5 rounded-full border border-secondary-soft bg-white px-2.5 py-1 text-xs font-semibold text-secondary-dark transition-colors hover:bg-secondary-soft dark:border-line dark:bg-surface-1 dark:text-ink dark:hover:bg-surface-2"
            >
                <flux:icon :name="$city['nationwide'] ? 'globe-alt' : 'map-pin'" variant="micro" class="size-3.5 text-secondary-medium dark:text-ink-muted" />
                {{ $city['nationwide'] ? __('cities.nationwide') : $city['name'] }}
                <span class="text-secondary-medium dark:text-ink-muted">· {{ $city['projectsLabel'] }}</span>
            </a>
        @endforeach
    </div>
@endif
