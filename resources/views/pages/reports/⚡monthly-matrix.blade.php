<?php

use App\Livewire\Concerns\Reports\BuildsMatrixActivities;
use App\Livewire\Concerns\Reports\BuildsMatrixBreakdowns;
use App\Livewire\Concerns\Reports\BuildsMonthlyMatrix;
use App\Livewire\Concerns\Reports\ExportsMonthlyMatrix;
use App\Livewire\Concerns\Reports\GroupsMonthlyMatrix;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.monthly_matrix')] class extends Component {
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

    /** A project id: a center is the project that runs from it. */
    #[Url(except: '')]
    public string $center = '';

    #[Url(except: '')]
    public string $city = '';

    /** A library indicator id. */
    #[Url(except: '')]
    public string $indicator = '';

    #[Url(except: '')]
    public string $pillar = '';

    /** A calendar year, or a grant year while one proposal is filtered. */
    #[Url(except: '')]
    public string $year = '';

    /** Only the projects the viewer's MEAL coverage names. */
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

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function toggleMine(): void
    {
        $this->mine = ! $this->mine;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['proposal', 'donor', 'center', 'city', 'indicator', 'pillar', 'year', 'mine']);
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
    $filtersAreActive = $proposal !== '' || $donor !== '' || $center !== '' || $city !== '' || $indicator !== '' || $pillar !== '' || $year !== '' || $mine;
    $filters = $this->resolvedFilters;
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="monthly-matrix-heading"
>
    <x-ui.page-header
        heading-id="monthly-matrix-heading"
        :title="__('titles.monthly_matrix')"
        :count="$onActivities ? $board['count'] : $paged->total()"
    >
        <x-slot:filters>
            <div class="contents" data-matrix-filters>
                <x-ui.header-filter-select :label="__('reports.matrix.proposal')" wire:model.live="proposal" :value="(string) ($filters['proposal'] ?? '')">
                    <option value="">{{ __('reports.matrix.all_proposals') }}</option>
                    @foreach ($this->proposalOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.donor')" wire:model.live="donor" :value="(string) ($filters['donor'] ?? '')">
                    <option value="">{{ __('reports.matrix.all_donors') }}</option>
                    @foreach ($this->donorOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.center')" wire:model.live="center" :value="(string) ($filters['center'] ?? '')">
                    <option value="">{{ __('reports.matrix.all_centers') }}</option>
                    @foreach ($this->projectOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('reports.matrix.city')" wire:model.live="city" :value="(string) ($filters['city'] ?? '')">
                    <option value="">{{ __('reports.matrix.all_cities') }}</option>
                    @foreach ($this->cityOptions as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                @unless ($onActivities)
                    <x-ui.header-filter-select :label="__('reports.matrix.indicator')" wire:model.live="indicator" :value="(string) ($filters['indicator'] ?? '')">
                        <option value="">{{ __('reports.matrix.all_indicators') }}</option>
                        @foreach ($this->indicatorOptions as $option)
                            <option value="{{ $option->id }}">{{ $option->code }} · {{ app()->getLocale() === 'ar' ? $option->name_ar : ($option->name_en ?? $option->name_ar) }}</option>
                        @endforeach
                    </x-ui.header-filter-select>
                @endunless

                <x-ui.header-filter-select :label="__('reports.matrix.pillar')" wire:model.live="pillar" :value="(string) ($filters['pillar'] ?? '')">
                    <option value="">{{ __('reports.matrix.all_pillars') }}</option>
                    @foreach ($this->pillarOptions as $option)
                        <option value="{{ $option->id }}">{{ app()->getLocale() === 'ar' ? $option->name_ar : ($option->name_en ?? $option->name_ar) }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="$filters['proposal'] !== null ? __('reports.matrix.grant_year_label') : __('reports.matrix.year')" wire:model.live="year" :value="(string) $this->selectedYear">
                    @foreach ($this->yearOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <div class="flex items-end">
                    <x-ui.button tone="secondary" icon="user" class="min-h-11 w-full touch-manipulation" wire:click="toggleMine" aria-pressed="{{ $mine ? 'true' : 'false' }}" data-test="matrix-mine">
                        @if ($mine)<flux:icon.check variant="micro" class="size-4" aria-hidden="true" />@endif
                        {{ __('reports.matrix.my_projects') }}
                    </x-ui.button>
                </div>
            </div>
        </x-slot:filters>

        <x-slot:actions>
            <span data-matrix-export>
                <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                    <span wire:loading.remove wire:target="exportCsv">{{ __('reports.matrix.export_csv') }}</span>
                    <span wire:loading wire:target="exportCsv">{{ __('reports.matrix.exporting') }}</span>
                </x-ui.button>
            </span>
        </x-slot:actions>
    </x-ui.page-header>

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

    @if ($onActivities)
        @include('pages.reports._monthly-matrix-activities')
    @elseif ($paged->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="table-cells"
                :heading="$this->hasNoAssignedProjects ? __('reports.matrix.no_assigned_projects') : __('reports.matrix.no_matching_indicators')"
                :body="$this->hasNoAssignedProjects ? __('reports.matrix.no_assigned_projects_hint') : __('common.change_the_search_or_filters_and_try_again')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button tone="primary" icon="arrow-path" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        @include('pages.reports._monthly-matrix-legend')
        @include('pages.reports._monthly-matrix-table')
    @endif
</section>
