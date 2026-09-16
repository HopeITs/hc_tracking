<?php

use App\Models\HrCity;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\AnnualPlan;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.executive_summary')] class extends Component {
    #[Url(except: '')]
    public string $year = '';

    /**
     * The fiscal years that can be summarised, newest first.
     *
     * The current year is always offered even when nothing has been planned for
     * it yet: "no plan written this year" is a finding, not a reason to hide the
     * year from the picker.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        $years = AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->push((int) now()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $years;
    }

    /**
     * The four coverage measures, in the order they are rendered.
     *
     * Each entry carries the raw pair as well as the percentage, so a card can
     * always show what the percentage was derived from.
     *
     * @return array<int, array{key: string, label: string, icon: string, covered: int, total: int, unit: string, scope: string, description: string}>
     */
    #[Computed]
    public function coverageMetrics(): array
    {
        $year = $this->selectedYear();
        $fiscalScope = __('common.fy_year', ['year' => $year]);

        return [
            [
                'key' => 'annual-plan-coverage',
                'label' => __('executive_summary.annual_plan_coverage'),
                'icon' => 'calendar-days',
                // annual_plans is unique on (program_id, year), so a count of
                // this year's plans is exactly a count of programs planned.
                'covered' => AnnualPlan::query()->where('year', $year)->count(),
                'total' => Program::query()->count(),
                'unit' => __('executive_summary.programs'),
                'scope' => $fiscalScope,
                'description' => __('executive_summary.programs_holding_an_annual_plan_for_this_fiscal_year'),
            ],
            [
                'key' => 'indicators-targeted',
                'label' => __('executive_summary.indicators_targeted'),
                'icon' => 'chart-bar',
                'covered' => Indicator::query()->whereHas('planIndicators', $this->committedThisYear($year))->count(),
                'total' => Indicator::query()->count(),
                'unit' => __('executive_summary.indicators'),
                'scope' => $fiscalScope,
                'description' => __('executive_summary.indicators_carrying_a_target_committed_in_an_annual_plan'),
            ],
            [
                'key' => 'projects-targeted',
                'label' => __('executive_summary.projects_carrying_a_target'),
                'icon' => 'rectangle-stack',
                'covered' => Project::query()->whereHas('planIndicators', $this->committedThisYear($year))->count(),
                'total' => Project::query()->count(),
                'unit' => __('executive_summary.projects'),
                'scope' => $fiscalScope,
                'description' => __('executive_summary.projects_named_by_a_target_in_this_year_s_annual_plans'),
            ],
            [
                'key' => 'geographic-reach',
                'label' => __('executive_summary.geographic_reach'),
                'icon' => 'map-pin',
                // Cities live in the HR database, so the covered count cannot
                // be a whereHas() across connections.
                'covered' => HrCity::query()->whereIn('id', Project::query()->distinct()->pluck('city_id'))->count(),
                'total' => HrCity::query()->count(),
                'unit' => __('executive_summary.cities'),
                // Projects carry no year, so this one measures the portfolio
                // rather than the fiscal year, and says so on the card.
                'scope' => __('executive_summary.portfolio'),
                'description' => __('executive_summary.cities_reached_by_at_least_one_project'),
            ],
        ];
    }

    /**
     * The share of a total that is covered, or null when there is no total.
     *
     * A partial figure never rounds up to 100: a single unplanned program out of
     * a thousand must not report full coverage.
     */
    public function percentageOf(int $covered, int $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        if ($covered >= $total) {
            return 100;
        }

        return min(99, (int) floor($covered / $total * 100));
    }

    /**
     * The fiscal year being summarised.
     *
     * An unknown or missing year falls back to the current one rather than
     * summarising nothing, so a stale or hand-edited URL still renders.
     */
    private function selectedYear(): int
    {
        $year = (int) $this->year;

        return in_array($year, $this->yearOptions, true) ? $year : (int) now()->year;
    }

    /**
     * Constrain plan indicators to the targets committed in a year's plans.
     *
     * @return callable(Builder<\App\Models\PlanIndicator>): void
     */
    private function committedThisYear(int $year): callable
    {
        return function (Builder $query) use ($year): void {
            $query
                ->where('source_type', 'annual_plan')
                ->whereIn('source_id', AnnualPlan::query()->select('id')->where('year', $year));
        };
    }
};
?>

<section
    class="admin-directory @container/summary flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white!"
    aria-labelledby="executive-summary-heading"
>
    <x-ui.page-header heading-id="executive-summary-heading" :title="__('common.executive_summary')">
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('executive_summary.fiscal_year')" wire:model.live="year" :value="$year">
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'summary-year-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>
    </x-ui.page-header>

    <div class="flex items-center justify-end">
        <span wire:loading.delay.flex wire:target="year" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}
        </span>
    </div>

    <div class="grid gap-4 @xl/summary:grid-cols-2 @5xl/summary:grid-cols-4">
        @foreach ($this->coverageMetrics as $metric)
            @php
                $percentage = $this->percentageOf($metric['covered'], $metric['total']);
                $barWidth = $percentage ?? 0;
            @endphp

            <article
                class="flex flex-col gap-4 rounded-lg border border-secondary-light bg-white p-4 dark:border-secondary-medium dark:bg-secondary-dark"
                wire:key="metric-{{ $metric['key'] }}"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                        <flux:icon :icon="$metric['icon']" class="size-5" />
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">
                        {{ $metric['scope'] }}
                    </span>
                </div>

                <div class="space-y-1">
                    <h2 class="text-sm font-medium text-secondary-medium dark:text-secondary-soft">{{ $metric['label'] }}</h2>
                    <p class="text-3xl font-semibold tabular-nums text-secondary-dark dark:text-white">
                        {{ $percentage === null ? '—' : $percentage.'%' }}
                    </p>
                    <p class="text-xs font-medium tabular-nums text-secondary-medium dark:text-secondary-soft">
                        {{ __('executive_summary.covered_of_total_unit', ['covered' => $metric['covered'], 'total' => $metric['total'], 'unit' => $metric['unit']]) }}
                    </p>
                </div>

                <div
                    class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium"
                    role="progressbar"
                    aria-valuenow="{{ $barWidth }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="{{ $metric['label'] }}"
                >
                    <div class="h-full rounded-full bg-primary" style="width: {{ $barWidth }}%"></div>
                </div>

                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ $metric['description'] }}</p>
            </article>
        @endforeach
    </div>
</section>
