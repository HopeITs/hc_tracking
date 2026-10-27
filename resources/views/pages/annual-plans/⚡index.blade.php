<?php

use App\Livewire\Concerns\AnnualPlans\BuildsPlanMatrix;
use App\Livewire\Concerns\AnnualPlans\FiltersPlanMatrix;
use App\Livewire\Concerns\AnnualPlans\ManagesAnnualPlanForm;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.annual_plans')] class extends Component {
    use BuildsPlanMatrix;
    use FiltersPlanMatrix;
    use ManagesAnnualPlanForm;
    use WithPagination;

    /**
     * The fiscal years the grid shows as columns.
     *
     * A set rather than a range: a reader comparing 2023 against 2027 should
     * not have to carry the four years between them. Empty means "every year
     * that has a plan", which is the view the page opens on.
     *
     * @var array<int, int>
     */
    #[Url(except: [])]
    public array $years = [];

    #[Url(except: '')]
    public string $manager = '';

    /**
     * The program-name search narrowing the matrix's rows.
     */
    #[Url(as: 'q', except: '')]
    public string $programSearch = '';

    /**
     * The fiscal year the cockpit reads, or '' to let it pick one.
     */
    #[Url(except: '')]
    public string $focusYear = '';
};
?>

@php
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-field-line! dark:bg-surface-1! dark:text-ink! dark:hover:bg-surface-2!';

    $yearColumns = $this->yearColumns;
    $yearRange = $this->yearRange;
    $yearStats = $this->yearStats;
    $yearRail = $this->yearRail;
    $cockpit = $this->cockpit;
    $delivery = $this->planDelivery;
    $activeYear = $this->activeYear;
    $filtersAreActive = $years !== [] || $manager !== '' || $programSearch !== '';
@endphp

<x-desk.register
    class="admin-directory @container/matrix"
    heading-id="annual-plan-matrix-heading"
    :title="__('common.annual_plans')"
    :count="array_sum(array_column($yearStats, 'plans'))"
    x-data="{ hoverYear: null, hoverProgram: null, gapsOnly: false }"
>
    <x-slot:actions>
        @can('create', App\Models\AnnualPlan::class)
            <button type="button" class="dk-btn dk-primary" wire:click="openCreateModal" data-test="create-annual-plan-button" data-quick-create><x-desk.icon name="plus" />{{ __('annual_plans.new_annual_plan') }}</button>
        @endcan
    </x-slot:actions>
    <x-slot:search>
        <x-desk.register-search :label="__('annual_plans.search_programs')" :placeholder="__('annual_plans.search_programs_placeholder')" wire:model.live.debounce.300ms="programSearch" />
    </x-slot:search>
    <x-slot:filters>

            <x-ui.header-filter-multiselect
                id="annual-plan-years"
                :label="__('annual_plans.fiscal_year')"
                :empty-label="__('annual_plans.all_fiscal_years')"
                :selected="$years"
                toggle="toggleYear"
                clear="clearYears"
            >
                @foreach ($this->yearOptions as $yearOption)
                    <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                @endforeach
            </x-ui.header-filter-multiselect>

            <x-ui.header-filter-select id="annual-plan-manager" :label="__('annual_plans.assigned_manager')" wire:model.live="manager" :value="$manager">
                <option value="">{{ __('common.all_managers') }}</option>
                @foreach ($this->managerOptions as $managerOption)
                    <option value="{{ $managerOption->id }}">
                        {{ $managerOption->name }} · {{ $managerOption->secondary_name }}
                    </option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>


    <div class="flex justify-end">
        <span wire:loading.delay.flex wire:target="toggleYear,clearYears,manager,programSearch,clearFilters,focusOnYear" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted">
            <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}
        </span>
        @if ($filtersAreActive)
            <flux:button size="sm" variant="ghost" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        @endif
    </div>

    @if ($yearColumns === [])
        <x-ui.empty-state
            icon="calendar-days"
            :heading="__('common.no_annual_plans_yet')"
            :body="__('annual_plans.once_a_program_has_an_annual_plan_its_fiscal_years_appear_he')"
            class="rounded-xl border border-secondary-soft dark:border-line"
        >
            <x-slot:action>
                @can('create', App\Models\AnnualPlan::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('annual_plans.new_annual_plan') }}</x-ui.button>@endcan
            </x-slot:action>
        </x-ui.empty-state>
    @else
        @php
            // The arc is plotted in its own coordinate space; every marker,
            // value label and year button is then placed with the same
            // percentages, so the curve and the HTML overlaid on top of it
            // never drift apart however the panel is stretched.
            $arcViewW = 720;
            $arcViewH = 150;
            // The tallest point still stops short of the top edge, leaving
            // room for its value label to sit above the dot without
            // colliding with the legend row.
            $arcPlotTop = $arcViewH * 0.24;
            $arcCount = count($yearRail);
            $arcPadX = $arcCount > 1 ? $arcViewW * 0.045 : 0;

            $arcPoints = collect($yearRail)->values()->map(function (array $rail, int $i) use ($arcCount, $arcViewW, $arcViewH, $arcPadX, $arcPlotTop): array {
                $x = $arcCount > 1
                    ? $arcPadX + $i * (($arcViewW - 2 * $arcPadX) / ($arcCount - 1))
                    : $arcViewW / 2;

                $planY = $arcPlotTop + (1 - max(4, $rail['planShare']) / 100) * ($arcViewH - $arcPlotTop);
                $targetY = $arcPlotTop + (1 - max(2, $rail['targetShare']) / 100) * ($arcViewH - $arcPlotTop);

                return [
                    'rail' => $rail,
                    'x' => $x,
                    'xPct' => $arcViewW > 0 ? round($x / $arcViewW * 100, 3) : 0,
                    'planY' => $planY,
                    'planYPct' => $arcViewH > 0 ? round($planY / $arcViewH * 100, 3) : 0,
                    'targetY' => $targetY,
                ];
            })->all();

            /**
             * A smoothed path through a series of points.
             *
             * Each segment eases through its own midpoint rather than joining
             * points with straight lines, so the arc reads as one continuous
             * curve instead of a jagged plot.
             */
            $buildSmoothPath = function (array $points, string $yKey): string {
                if ($points === []) {
                    return '';
                }

                $path = sprintf('M%.2F,%.2F', $points[0]['x'], $points[0][$yKey]);

                for ($i = 1; $i < count($points); $i++) {
                    $prev = $points[$i - 1];
                    $curr = $points[$i];
                    $midX = ($prev['x'] + $curr['x']) / 2;

                    $path .= sprintf(' C%.2F,%.2F %.2F,%.2F %.2F,%.2F', $midX, $prev[$yKey], $midX, $curr[$yKey], $curr['x'], $curr[$yKey]);
                }

                return $path;
            };

            $arcPlanLine = $buildSmoothPath($arcPoints, 'planY');
            $arcTargetLine = $buildSmoothPath($arcPoints, 'targetY');

            $arcFirstPoint = $arcPoints[0] ?? null;
            $arcLastPoint = $arcPoints === [] ? null : end($arcPoints);
            $arcPlanArea = $arcFirstPoint === null ? '' : $arcPlanLine
                .sprintf(' L%.2F,%.2F', $arcLastPoint['x'], $arcViewH)
                .sprintf(' L%.2F,%.2F Z', $arcFirstPoint['x'], $arcViewH);

            $gaugeRadius = 46;
            $gaugeCircumference = 2 * M_PI * $gaugeRadius;
            $gaugeShare = $cockpit === null ? 0 : min(100, max(0, $cockpit['coverage']));
            $gaugeOffset = $gaugeCircumference * (1 - $gaugeShare / 100);
        @endphp

        @include('pages.annual-plans._index-arc')

        @include('pages.annual-plans._index-matrix')
    @endif

    @include('pages.annual-plans._index-plan-modal')

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('annual_plans.delete_this_annual_plan')"
        :message="$this->deletingPlan === null ? null : ($this->deletingPlan['targets'] > 0
            ? __('annual_plans.deleting_the_plan_also_removes_its_targets_and_their_reports', [
                'program' => $this->deletingPlan['plan']->program?->name ?? __('common.program'),
                'year' => $this->deletingPlan['plan']->year,
                'count' => $this->deletingPlan['targets'],
            ])
            : __('annual_plans.this_plan_has_no_targets_committed_in_it', [
                'program' => $this->deletingPlan['plan']->program?->name ?? __('common.program'),
                'year' => $this->deletingPlan['plan']->year,
            ]))"
        confirm="deletePlan"
        :confirm-label="__('annual_plans.delete_annual_plan')"
        confirm-test="confirm-delete-annual-plan"
    />
</x-desk.register>
