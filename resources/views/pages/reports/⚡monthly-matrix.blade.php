<?php

use App\Livewire\Concerns\Reports\BuildsMatrixActivities;
use App\Livewire\Concerns\Reports\BuildsMatrixBreakdowns;
use App\Livewire\Concerns\Reports\BuildsMonthlyMatrix;
use App\Livewire\Concerns\Reports\ExportsMonthlyMatrix;
use App\Livewire\Concerns\Reports\GroupsMonthlyMatrix;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.monthly_matrix')] class extends Component {
    use BuildsMatrixActivities;
    use BuildsMatrixBreakdowns;
    use BuildsMonthlyMatrix;
    use ExportsMonthlyMatrix;
    use GroupsMonthlyMatrix;
    use WithPagination;

    #[Url(except: '')]
    public string $proposal = '';

    #[Url(except: '')]
    public string $donor = '';

    /** A place: 'c{hrCenterId}', 'field-{cityId}' (Field work) or 'unset' (Center not set). */
    #[Url(except: '')]
    public string $center = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $program = '';

    /** A library indicator id. */
    #[Url(except: '')]
    public string $indicator = '';

    #[Url(except: '')]
    public string $pillar = '';

    /** The first month shown, 'Y-m'. */
    #[Url(except: '')]
    public string $from = '';

    /** The last month shown, 'Y-m'. */
    #[Url(except: '')]
    public string $to = '';

    /** Legacy: a calendar year, or a grant year of the one filtered proposal; translated to from/to on mount. */
    #[Url(except: '')]
    public string $year = '';

    /** Only the places the viewer's MEAL coverage names. */
    #[Url(except: false)]
    public bool $mine = false;

    /** 'rows' (one row per center) or 'grouped' (one row per indicator). */
    #[Url(except: 'rows')]
    public string $view = 'rows';

    /** 'matrix' or 'activities'. */
    #[Url(except: 'matrix')]
    public string $tab = 'matrix';

    #[Url(except: false)]
    public bool $pwd = false;

    /** @var list<string> the filters in the order the viewer applied them (emptyReason) */
    #[Locked]
    public array $filterOrder = [];

    public function mount(): void
    {
        $this->translateLegacyFilters();

        if ($this->from === '' && $this->to === '') {
            $this->setThisYearWindow();
        }

        $this->checkWindow();
    }

    public function updated(string $property): void
    {
        $name = in_array($property, ['from', 'to'], true) ? 'window' : $property;

        if (in_array($name, ['proposal', 'donor', 'city', 'program', 'center', 'indicator', 'pillar', 'window'], true)) {
            $this->filterOrder = [...array_values(array_diff($this->filterOrder, [$name])), $name];
        }

        if ($name === 'window') {
            $this->checkWindow();
        }

        $this->resetPage();
    }

    /** A center pre-selects its city. */
    public function updatedCenter(string $value): void
    {
        $cityId = $this->cityOfCenter($value);

        if ($cityId !== null) {
            $this->city = (string) $cityId;
        }
    }

    /** A city narrows the centers: a center elsewhere is cleared. */
    public function updatedCity(string $value): void
    {
        $centerCity = $this->cityOfCenter($this->center);

        if ($value !== '' && $centerCity !== null && (string) $centerCity !== $value) {
            $this->center = '';
        }
    }

    public function toggleMine(): void
    {
        $this->mine = ! $this->mine;
        $this->filterOrder = [...array_values(array_diff($this->filterOrder, ['mine'])), 'mine'];
        $this->resetPage();
    }

    /** January of this year → this month. */
    public function thisYear(): void
    {
        $this->setThisYearWindow();
        $this->checkWindow();
        $this->resetPage();
    }

    /** The one filtered proposal's period: actual (else planned) begin → effective end. */
    public function wholeGrant(): void
    {
        $period = $this->proposalPeriod;

        if ($period === null) {
            return;
        }

        $this->from = $period['begin']->format('Y-m');
        $this->to = $period['end']->format('Y-m');
        $this->checkWindow();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['proposal', 'donor', 'center', 'city', 'program', 'indicator', 'pillar', 'year', 'mine', 'filterOrder']);
        $this->setThisYearWindow();
        $this->checkWindow();
        $this->resetPage();
    }
};
?>

@php
    $onActivities = $this->resolvedTab === 'activities';
    $isGrouped = $this->resolvedView === 'grouped';
    $board = $onActivities ? $this->activityBoard : null;
    $matrix = $onActivities ? null : $this->matrix;
    $paged = $onActivities ? null : $this->pagedRows;
    $extraction = $this->extractionFilter;
    $windowMonths = $this->months;
    $monthFormat = __('formats.month_year');
    $filtersAreActive = $proposal !== '' || $donor !== '' || $center !== '' || $city !== '' || $program !== '' || $indicator !== '' || $pillar !== '' || $from !== '' || $to !== '' || $mine;
    $moreFiltersAreActive = $donor !== '' || $indicator !== '' || $pillar !== '' || $mine;
    $centerValue = match (true) {
        $extraction->centerId !== null => 'c'.$extraction->centerId,
        $extraction->cityWide, $extraction->centerUnset => $center,
        default => '',
    };
    $filterLabels = [
        'proposal' => __('reports.matrix.proposal'),
        'donor' => __('reports.matrix.donor'),
        'center' => __('reports.matrix.center'),
        'city' => __('reports.matrix.city'),
        'program' => __('reports.matrix.program'),
        'indicator' => __('reports.matrix.indicator'),
        'pillar' => __('reports.matrix.pillar'),
        'from' => __('reports.matrix.window'),
        'to' => __('reports.matrix.window'),
        'window' => __('reports.matrix.window'),
        'mine' => __('reports.matrix.my_projects'),
    ];
    $monthFieldClass = 'block h-9 w-full min-w-0 touch-manipulation rounded-[10px] border border-(--line-2) bg-(--paper) px-2.5 text-[12.5px] font-semibold text-(--ink) hover:border-(--ink-3) max-[720px]:h-11 aria-invalid:border-status-critical';
    $monthLabelClass = 'mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-ink-muted';
@endphp

<x-desk.register class="@container/directory" heading-id="monthly-matrix-heading" :title="__('titles.monthly_matrix')" :count="$onActivities ? $board['count'] : $paged->total()"
    >
        <x-slot:filters>
            <div class="contents" data-matrix-filters>
                <x-ui.header-filter-select :label="__('reports.matrix.proposal')" wire:model.live="proposal" :value="(string) ($extraction->proposalId ?? '')">
                    <option value="">{{ __('reports.matrix.all_proposals') }}</option>
                    @foreach ($this->proposalOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.city')" wire:model.live="city" :value="(string) ($extraction->cityId ?? '')">
                    <option value="">{{ __('reports.matrix.all_cities') }}</option>
                    @foreach ($this->cityOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.program')" wire:model.live="program" :value="(string) ($extraction->programId ?? '')">
                    <option value="">{{ __('reports.matrix.all_programs') }}</option>
                    @foreach ($this->programOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.center')" wire:model.live="center" :value="$centerValue">
                    <option value="">{{ __('reports.matrix.all_centers') }}</option>
                    @foreach ($this->centerOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <div class="min-w-0" data-matrix-from>
                    <label for="matrix-from" class="{{ $monthLabelClass }}">{{ __('reports.matrix.from') }}</label>
                    <input id="matrix-from" type="month" wire:model.live="from" class="{{ $monthFieldClass }}" dir="ltr" @error('from') aria-invalid="true" aria-describedby="matrix-from-error" @enderror />
                    @error('from')
                        <p id="matrix-from-error" class="mt-1 flex items-center gap-1 text-xs text-status-critical" data-matrix-from-error>
                            <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <div class="min-w-0" data-matrix-to>
                    <label for="matrix-to" class="{{ $monthLabelClass }}">{{ __('reports.matrix.to') }}</label>
                    <input id="matrix-to" type="month" wire:model.live="to" class="{{ $monthFieldClass }}" dir="ltr" />
                </div>

                <div class="contents" x-data="{ open: @js($moreFiltersAreActive) }">
                    <div class="flex items-end">
                        <button type="button" class="dk-btn min-h-11 w-full touch-manipulation" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="matrix-more-filters" data-test="more-filters-button">
                            <flux:icon.adjustments-horizontal variant="micro" class="size-4" aria-hidden="true" />
                            {{ __('reports.matrix.more_filters') }}
                        </button>
                    </div>

                    <div id="matrix-more-filters" class="contents" x-show="open" x-cloak data-matrix-more-filters>
                        <x-ui.header-filter-select :label="__('reports.matrix.donor')" wire:model.live="donor" :value="(string) ($extraction->donorId ?? '')">
                            <option value="">{{ __('reports.matrix.all_donors') }}</option>
                            @foreach ($this->donorOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.header-filter-select>

                        @unless ($onActivities)
                            <x-ui.header-filter-select :label="__('reports.matrix.indicator')" wire:model.live="indicator" :value="(string) ($extraction->indicatorId ?? '')">
                                <option value="">{{ __('reports.matrix.all_indicators') }}</option>
                                @foreach ($this->indicatorOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->code }} · {{ app()->getLocale() === 'ar' ? $option->name_ar : ($option->name_en ?? $option->name_ar) }}</option>
                                @endforeach
                            </x-ui.header-filter-select>
                        @endunless

                        <x-ui.header-filter-select :label="__('reports.matrix.pillar')" wire:model.live="pillar" :value="(string) ($extraction->pillarId ?? '')">
                            <option value="">{{ __('reports.matrix.all_pillars') }}</option>
                            @foreach ($this->pillarOptions as $option)
                                <option value="{{ $option->id }}">{{ app()->getLocale() === 'ar' ? $option->name_ar : ($option->name_en ?? $option->name_ar) }}</option>
                            @endforeach
                        </x-ui.header-filter-select>

                        <div class="flex items-end">
                            <x-ui.button tone="secondary" icon="user" class="min-h-11 w-full touch-manipulation" wire:click="toggleMine" aria-pressed="{{ $mine ? 'true' : 'false' }}" data-test="matrix-mine">
                                @if ($mine)<flux:icon.check variant="micro" class="size-4" aria-hidden="true" />@endif
                                {{ __('reports.matrix.my_projects') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="secondary" icon="calendar" class="min-h-11 touch-manipulation" wire:click="thisYear" data-test="matrix-this-year">
                {{ __('reports.matrix.this_year') }}
            </x-ui.button>
            @if ($this->hasWholeGrant)
                <x-ui.button tone="secondary" icon="calendar-days" class="min-h-11 touch-manipulation" wire:click="wholeGrant" data-test="matrix-whole-grant">
                    {{ __('reports.matrix.whole_grant') }}
                </x-ui.button>
            @endif
            <span data-matrix-export>
                <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                    <span wire:loading.remove wire:target="exportCsv">{{ __('reports.matrix.export_csv') }}</span>
                    <span wire:loading wire:target="exportCsv">{{ __('reports.matrix.exporting') }}</span>
                </x-ui.button>
            </span>
        </x-slot:actions>


    <div class="flex flex-wrap items-end justify-between gap-3" data-matrix-modes>
        <x-ui.relation-tabs
            wire-target="tab"
            :active="$this->resolvedTab"
            :tabs="[
                ['key' => 'matrix', 'label' => __('reports.matrix.tab_matrix')],
                ['key' => 'activities', 'label' => __('reports.matrix.tab_activities')],
            ]"
        />

        @unless ($onActivities)
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1" role="group" aria-label="{{ __('reports.matrix.view_label') }}">
                    <x-ui.button tone="secondary" :icon="$isGrouped ? null : 'check'" class="min-h-11 touch-manipulation" wire:click="$set('view', 'rows')" aria-pressed="{{ $isGrouped ? 'false' : 'true' }}" data-test="matrix-view-rows">
                        {{ __('reports.matrix.view_rows') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" :icon="$isGrouped ? 'check' : null" class="min-h-11 touch-manipulation" wire:click="$set('view', 'grouped')" aria-pressed="{{ $isGrouped ? 'true' : 'false' }}" data-test="matrix-view-grouped">
                        {{ __('reports.matrix.view_grouped') }}
                    </x-ui.button>
                </div>
                <x-ui.button tone="secondary" :icon="$pwd ? 'check' : null" class="min-h-11 touch-manipulation" wire:click="$toggle('pwd')" aria-pressed="{{ $pwd ? 'true' : 'false' }}" data-test="matrix-pwd">
                    {{ __('reports.matrix.show_pwd') }}
                </x-ui.button>
            </div>
        @endunless
    </div>

    @if ($windowMonths !== [])
        <p class="text-sm text-secondary-medium dark:text-ink-muted" data-matrix-window>
            {{ __('reports.matrix.window_shown', ['from' => $windowMonths[0]->translatedFormat($monthFormat), 'to' => $windowMonths[array_key_last($windowMonths)]->translatedFormat($monthFormat)]) }}
        </p>
    @endif

    @if ($onActivities)
        @include('pages.reports._monthly-matrix-activities')
    @elseif ($paged->isEmpty())
        @include('pages.reports._monthly-matrix-empty', ['emptyIcon' => 'table-cells', 'emptyHeading' => __('reports.matrix.no_matching_indicators')])
    @else
        @include('pages.reports._monthly-matrix-legend')
        @include('pages.reports._monthly-matrix-table')
    @endif
</x-desk.register>
