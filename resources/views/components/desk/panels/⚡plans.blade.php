<?php

use App\Enums\RagStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Annual plans: planned vs actual for the picked year, per program plan.
 * Plans hold targets, not activities (no activity calendar exists for
 * annual plans yet), so "done" is the average share achieved across the
 * plan's targets (PlanIndicator::percentAchieved, approved reports only,
 * capped at 100), "expected" is how much of the year has passed (100% for
 * a past year, 0% for a future one), and the targets behind are those not
 * green by RagStatus::rate. Year, program and status (on pace / behind)
 * are dropdowns in the filter popup.
 */
new class extends Component {
    use IsDeskPanel;

    public string $year = '';

    public function updatedYear(string $year): void
    {
        $this->year = in_array((int) $year, $this->years, true) ? (string) (int) $year : '';
        $this->page = 0;
        unset($this->rows);
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function years(): array
    {
        return array_values($this->visible(AnnualPlan::query(), 'annual-plans')->distinct()->orderBy('year')->pluck('year')->map(fn ($year): int => (int) $year)->all());
    }

    public function shownYear(): int
    {
        $years = $this->years;

        if ($this->year !== '' && in_array((int) $this->year, $years, true)) {
            return (int) $this->year;
        }

        return in_array((int) now()->year, $years, true) ? (int) now()->year : (int) (end($years) ?: now()->year);
    }

    /**
     * Share of the year passed, 0–100.
     */
    public function expected(): float
    {
        $year = $this->shownYear();
        $start = CarbonImmutable::create($year)->startOfYear();
        $end = $start->endOfYear();

        return round(min(100.0, max(0.0, $start->diffInSeconds(now(), false) / $start->diffInSeconds($end) * 100)), 1);
    }

    /**
     * @return Collection<int, array{plan: AnnualPlan, done: float, targets: int, behind: int, onPace: bool}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $expected = $this->expected();

        return $this->visible(AnnualPlan::query(), 'annual-plans')
            ->with(['program:id', 'manager', 'planIndicators' => fn ($query) => $query->select(['id', 'source_type', 'source_id', 'indicator_id', 'target']), 'planIndicators.indicator:id,measure', 'planIndicators.reports:id,plan_indicator_id,status,value,planned_date'])
            ->where('year', $this->shownYear())
            ->get()
            ->map(function (AnnualPlan $plan) use ($expected): array {
                $shares = $plan->planIndicators->map(fn (PlanIndicator $target): ?float => $target->percentAchieved())->filter(fn (?float $share): bool => $share !== null);
                $behind = $shares->filter(fn (float $share): bool => RagStatus::rate($share, $expected) !== RagStatus::Green)->count();

                return [
                    'plan' => $plan,
                    'done' => $shares->isEmpty() ? 0.0 : round($shares->map(fn (float $share): float => min(100.0, $share))->avg(), 1),
                    'targets' => $plan->planIndicators->count(),
                    'behind' => $behind,
                    'onPace' => $behind === 0,
                ];
            })
            ->sortBy([fn (array $a, array $b): int => $b['behind'] <=> $a['behind'], fn (array $a, array $b): int => (string) $a['plan']->program?->name <=> (string) $b['plan']->program?->name])
            ->values();
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return array_filter([
            'program' => $row['plan']->program?->name,
            'status' => __($row['onPace'] ? 'desk.plan_on_pace' : 'desk.plan_behind'),
        ]);
    }
};
?>

@php
    $shown = $this->shownYear();
    $expected = $this->expected();
    $pg = $this->paginate($this->rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" :filtered="$year !== '' && (int) $year !== (int) now()->year" id="plans" register="annual-plans.index" :size="$size" :index="$index" :total="$total" :count="$this->rows->count()">
    @if (count($this->years) > 1)
        <x-slot:filters>
            <div class="contents" data-filter-defaults='{"year":""}'>
                <x-ui.header-filter-select :label="__('desk.facet.year')" wire:model="year" :value="$year" deferred>
                    <option value="">{{ __('desk.this_year') }}</option>
                    @foreach ($this->years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            </div>
        </x-slot:filters>
    @endif

    <p class="dk-pl-sum">{{ __('desk.plans_expected', ['year' => $shown, 'expected' => (int) round($expected)]) }}</p>

    @if ($pg['total'] > 0)
        <ul class="dk-ap">
            @foreach ($pg['slice'] as $row)
                @php
                    $plan = $row['plan'];
                @endphp
                <li wire:key="plan-{{ $plan->id }}" @class(['dk-behind' => ! $row['onPace']])>
                    <a href="{{ route('annual-plans.show', $plan) }}" wire:navigate class="dk-ap-n" title="{{ $plan->program?->name }}">{{ $plan->program?->name ?? '—' }}</a>
                    <span class="dk-ap-bar" title="{{ __('desk.plans_bar', ['done' => (int) round($row['done']), 'expected' => (int) round($expected)]) }}"><span class="dk-meter dk-res"><b style="width:{{ $row['done'] }}%"></b><span class="dk-tick" style="inset-inline-start:{{ $expected }}%"></span></span></span>
                    <span class="dk-ap-v dk-num"><b>{{ (int) round($row['done']) }}%</b> / {{ (int) round($expected) }}%</span>
                    <span class="dk-is dk-ap-m">@if ($row['targets'] === 0){{ __('desk.plan_no_targets') }}@else<span @class(['dk-bad' => $row['behind'] > 0])>{{ trans_choice('desk.plan_targets_behind', $row['behind'], ['count' => $row['behind'], 'total' => $row['targets']]) }}</span>@endif @if ($plan->manager !== null) · {{ $plan->manager->name }}@endif</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.plans.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
