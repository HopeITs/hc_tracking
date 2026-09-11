<?php

use App\Models\City;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Executive Summary')] class extends Component {
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
        $years = YearlyPlan::query()
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
        $fiscalScope = __('FY :year', ['year' => $year]);

        return [
            [
                'key' => 'annual-plan-coverage',
                'label' => __('Annual plan coverage'),
                'icon' => 'calendar-days',
                // yearly_plans is unique on (program_id, year), so a count of
                // this year's plans is exactly a count of programs planned.
                'covered' => YearlyPlan::query()->where('year', $year)->count(),
                'total' => Program::query()->count(),
                'unit' => __('programs'),
                'scope' => $fiscalScope,
                'description' => __('Programs holding an annual plan for this fiscal year.'),
            ],
            [
                'key' => 'indicators-targeted',
                'label' => __('Indicators targeted'),
                'icon' => 'chart-bar',
                'covered' => Indicator::query()->whereHas('planIndicators', $this->committedThisYear($year))->count(),
                'total' => Indicator::query()->count(),
                'unit' => __('indicators'),
                'scope' => $fiscalScope,
                'description' => __('Indicators carrying a target committed in an annual plan.'),
            ],
            [
                'key' => 'projects-targeted',
                'label' => __('Projects carrying a target'),
                'icon' => 'rectangle-stack',
                'covered' => Project::query()->whereHas('planIndicators', $this->committedThisYear($year))->count(),
                'total' => Project::query()->count(),
                'unit' => __('projects'),
                'scope' => $fiscalScope,
                'description' => __('Projects named by a target in this year\'s annual plans.'),
            ],
            [
                'key' => 'geographic-reach',
                'label' => __('Geographic reach'),
                'icon' => 'map-pin',
                'covered' => City::query()->whereHas('projects')->count(),
                'total' => City::query()->count(),
                'unit' => __('cities'),
                // Projects carry no year, so this one measures the portfolio
                // rather than the fiscal year, and says so on the card.
                'scope' => __('Portfolio'),
                'description' => __('Cities reached by at least one project.'),
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
                ->where('source_type', 'yearly_plan')
                ->whereIn('source_id', YearlyPlan::query()->select('id')->where('year', $year));
        };
    }
};
?>

@php
    $controlClasses = 'border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!';
@endphp

<section
    class="admin-directory @container/summary flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white!"
    aria-labelledby="executive-summary-heading"
>
    <header class="flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @3xl/summary:flex-row @3xl/summary:items-end">
        <div class="min-w-48 @3xl/summary:me-auto">
            <flux:heading id="executive-summary-heading" size="lg">{{ __('Executive Summary') }}</flux:heading>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('How much of the portfolio is planned, targeted and reached.') }}</p>
        </div>

        <div class="w-full @3xl/summary:max-w-56">
            <flux:select :label="__('Fiscal year')" :class="$controlClasses" wire:model.live="year">
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'summary-year-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </header>

    <div class="flex items-center justify-end">
        <span wire:loading.delay.flex wire:target="year" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('Updating') }}
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
                        {{ __(':covered of :total :unit', ['covered' => $metric['covered'], 'total' => $metric['total'], 'unit' => $metric['unit']]) }}
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
