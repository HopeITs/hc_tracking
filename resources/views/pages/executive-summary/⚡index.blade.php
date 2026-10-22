<?php

use App\Livewire\Concerns\ExecutiveSummary\LoadsSummarySources;
use App\Livewire\Concerns\ExecutiveSummary\ReadsSummaryCoverage;
use App\Livewire\Concerns\ExecutiveSummary\ReadsSummaryLedger;
use App\Models\AnnualPlan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.executive_summary')] class extends Component {
    use LoadsSummarySources;
    use ReadsSummaryCoverage;
    use ReadsSummaryLedger;

    /**
     * The fiscal year picked in the header, or '' while the picker is untouched.
     *
     * Kept as the raw string the select writes so a hand-edited URL never
     * throws; selectedYear() is what every figure on the page is built from.
     */
    #[Url(except: '')]
    public string $year = '';

    /**
     * The ledger row opened to the rows that produced its gap, or ''.
     */
    #[Url(except: '')]
    public string $openRow = '';

    /**
     * Open a ledger row on to its breakdown, or close the one already open.
     */
    public function toggleRow(string $key): void
    {
        $this->openRow = $this->openRow === $key ? '' : $key;
    }

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
        return AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->push((int) now()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * The fiscal year every figure on this page is built from.
     *
     * Public so the header picker can render the year actually being
     * summarised. Binding the picker to $year instead would show its first
     * option while the page reported a different year — the defect this
     * rebuild carries a fix for.
     */
    #[Computed]
    public function fiscalYear(): int
    {
        $year = (int) $this->year;

        return in_array($year, $this->yearOptions, true) ? $year : (int) now()->year;
    }

    /**
     * Everything this page measures, for the selected fiscal year.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function figures(): array
    {
        return $this->figuresFor($this->fiscalYear);
    }

    /**
     * The same measures one fiscal year earlier, for the deltas.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function priorFigures(): array
    {
        return $this->figuresFor($this->fiscalYear - 1);
    }
};
?>

@php
    /** Whole units at specimen scale: cents on a seven-figure sum are noise. */
    $amount = fn (int|float $value): string => number_format((float) round((float) $value));

    $toneInk = [
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-status-critical',
        'funding' => 'text-funding',
        'neutral' => 'text-secondary-medium dark:text-ink-muted',
    ];

    $toneFill = [
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-status-critical',
        'funding' => 'bg-funding',
        'neutral' => 'bg-secondary-light',
    ];

    $hairline = 'border-secondary-soft dark:border-line';
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $ink = 'text-secondary-dark dark:text-ink';
@endphp

<x-desk.register class="exec-ledger admin-directory @container/ledger [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! dark:[&_[data-flux-heading]]:text-ink! dark:[&_[data-flux-label]]:text-ink!" heading-id="executive-summary-heading" :title="__('common.executive_summary')">
        <x-slot:filters>
            <x-ui.header-filter-select
                :label="__('executive_summary.fiscal_year')"
                wire:model.live="year"
                :value="(string) $this->fiscalYear"
            >
                @foreach ($this->yearOptions as $yearOption)
                    <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>


    {{-- The struck balance: the year in one line, every figure half of a pair. --}}
    @include('pages.executive-summary._index-balance')

    {{-- The spread. Committed on the start side, delivered on the end side,
         one variance spine between them carrying every row's shortfall. --}}
    @include('pages.executive-summary._index-ledger')

    {{-- The coverage rail: the four measures, as one strip rather than four cards. --}}
    @include('pages.executive-summary._index-coverage')

    {{-- The year's stated reach. A commitment the schema records no delivery
         against, so it is stated as ambition rather than faked into a pair. --}}
    @include('pages.executive-summary._index-reach')

    <span wire:loading.delay.flex wire:target="year" class="hidden items-center gap-1.5 type-meta {{ $muted }}">
        <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}
    </span>
</x-desk.register>
