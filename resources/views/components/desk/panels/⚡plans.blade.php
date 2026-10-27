<?php

use App\Enums\ReportStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Annual plans: each program's plan for the picked year. Real plans carry
 * targets and their reports, not the artifact's activity-by-month grid, so
 * each row says how many targets the plan holds and how many of its
 * reports are approved (the annual-plans page's own delivery figures).
 */
new class extends Component {
    use IsDeskPanel;

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

        if (ctype_digit($this->filter) && in_array((int) $this->filter, $years, true)) {
            return (int) $this->filter;
        }

        return in_array((int) now()->year, $years, true) ? (int) now()->year : (int) (end($years) ?: now()->year);
    }

    /**
     * @return Collection<int, AnnualPlan>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(AnnualPlan::query(), 'annual-plans')
            ->with(['program:id', 'manager'])
            ->where('year', $this->shownYear())
            ->get()
            ->sortBy(fn (AnnualPlan $plan): string => (string) $plan->program?->name)
            ->values();
    }

    /**
     * plan id => targets, reports and approved reports.
     *
     * @return array<int, array{targets: int, reports: int, approved: int}>
     */
    #[Computed]
    public function delivery(): array
    {
        $ids = $this->rows->modelKeys();

        if ($ids === []) {
            return [];
        }

        return PlanIndicator::query()
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $ids)
            ->leftJoin('reports', 'reports.plan_indicator_id', '=', 'plan_indicators.id')
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id')
            ->selectRaw('count(distinct plan_indicators.id) as targets')
            ->selectRaw('count(distinct reports.id) as reports')
            ->selectRaw('count(distinct case when reports.status = ? then reports.id end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->plan_id => ['targets' => (int) $row->targets, 'reports' => (int) $row->reports, 'approved' => (int) $row->approved]])
            ->all();
    }
};
?>

@php
    $year = $this->shownYear();
    $pg = $this->paginate($this->rows, self::PerPage);
    $delivery = $this->delivery;
@endphp

<x-desk.panel :facets="$pg['facets']" id="plans" register="annual-plans.index" :size="$size" :index="$index" :total="$total">
    @if (count($this->years) > 0)
        <x-slot:tools>
            <div class="dk-seg" role="group">
                @foreach ($this->years as $option)
                    <button type="button" wire:click="setFilter('{{ $option }}')" aria-pressed="{{ $option === $year ? 'true' : 'false' }}">{{ $option }}</button>
                @endforeach
            </div>
        </x-slot:tools>
    @endif

    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $plan)
                @php
                    $figures = $delivery[$plan->id] ?? ['targets' => 0, 'reports' => 0, 'approved' => 0];
                @endphp
                <li wire:key="plan-{{ $plan->id }}">
                    <div>
                        <strong><a href="{{ route('annual-plans.show', $plan) }}" wire:navigate>{{ $plan->program?->name ?? '—' }} {{ $plan->year }}</a></strong>
                        <span class="dk-is">{{ trans_choice('desk.targets_count', $figures['targets'], ['count' => $figures['targets']]) }} · {{ __('desk.reports_approved', ['approved' => $figures['approved'], 'total' => $figures['reports']]) }}@if ($plan->manager !== null) · {{ __('desk.prepared_by') }} {{ $plan->manager->name }}@endif</span>
                    </div>
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
