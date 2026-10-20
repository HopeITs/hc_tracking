<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use App\Support\Desk\Money;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * At a glance: the portfolio in a few big numbers — projects running,
 * proposals being written or reviewed, last month's monthly reports
 * validated (with an eight-month line), the share of USD commitments
 * received (money viewers only) and how many things are late.
 */
new class extends Component {
    use ChartsDashboard;
    use IsDeskPanel;
    use ReadsDashboardAttention;

    /**
     * @return array{running: int, pipeline: int|null, reports: array{validated: int, total: int, month: CarbonImmutable, spark: list<int>}|null, money: array{share: int, committed: string}|null, late: int}
     */
    #[Computed]
    public function figures(): array
    {
        $lastMonth = CarbonImmutable::today()->startOfMonth()->subMonth();
        $reports = null;

        if ($this->canView('reports')) {
            $months = collect(range(7, 0))->map(fn (int $back): string => $lastMonth->subMonths($back)->toDateString());
            $validated = MonthlyReport::query()
                ->where('status', MonthlyReportStatus::Validated)
                ->whereIn('period', $months->all())
                ->selectRaw('period, count(*) as total')
                ->groupBy('period')
                ->pluck('total', 'period')
                ->mapWithKeys(fn ($total, $period): array => [CarbonImmutable::parse($period)->toDateString() => (int) $total]);

            $reports = [
                'validated' => (int) $validated->get($lastMonth->toDateString(), 0),
                'total' => MonthlyReport::query()->whereDate('period', $lastMonth)->count(),
                'month' => $lastMonth,
                'spark' => $months->map(fn (string $month): int => (int) $validated->get($month, 0))->all(),
            ];
        }

        $usd = $this->fundingPosition->firstWhere('currency', 'USD');

        return [
            'running' => $this->visible(Project::query(), 'projects')->where('status', ProjectStatus::Active)->count(),
            'pipeline' => $this->canView('proposals')
                ? Proposal::query()->whereIn('status', [...ProposalPhase::Ideation->statuses(), ...ProposalPhase::Planning->statuses()])->count()
                : null,
            'reports' => $reports,
            'money' => $usd === null || $usd['share'] === null ? null : [
                'share' => $usd['share'],
                'committed' => Money::format($usd['committed'], 'USD'),
            ],
            'late' => $this->attentionItems->filter(fn (array $item): bool => ($item['daysLate'] ?? 0) > 0)->count(),
        ];
    }
};
?>

@php
    $figures = $this->figures;
@endphp

<x-desk.panel id="glance" :size="$size" :index="$index" :total="$total">
    <div class="dk-figs">
        <div class="dk-fig"><strong class="dk-num">{{ $figures['running'] }}</strong><span>{{ __('desk.panel.glance.running') }}</span></div>
        @if ($figures['pipeline'] !== null)
            <div class="dk-fig"><strong class="dk-num">{{ $figures['pipeline'] }}</strong><span>{{ __('desk.panel.glance.pipeline') }}</span></div>
        @endif
        @if ($figures['reports'] !== null)
            @php
                $spark = $figures['reports']['spark'];
                $peak = max(1, ...$spark);
                $last = count($spark) - 1;
                $points = collect($spark)->map(fn (int $value, int $i): string => round($i / $last * 160, 1).','.round(38 - $value / $peak * 34, 1))->implode(' ');
            @endphp
            <div class="dk-fig">
                <strong class="dk-num">{{ $figures['reports']['validated'] }}/{{ $figures['reports']['total'] }}</strong>
                <span>{{ __('desk.panel.glance.reports', ['month' => $figures['reports']['month']->translatedFormat('F')]) }}</span>
                <svg class="dk-spark" viewBox="0 0 160 40" preserveAspectRatio="none" aria-hidden="true"><polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="160" cy="{{ round(38 - $spark[$last] / $peak * 34, 1) }}" r="3" fill="currentColor"/></svg>
            </div>
        @endif
        @if ($figures['money'] !== null)
            <div class="dk-fig"><strong class="dk-num">{{ $figures['money']['share'] }}%</strong><span>{{ __('desk.panel.glance.money', ['amount' => $figures['money']['committed']]) }}</span></div>
        @endif
        <div class="dk-fig"><strong class="dk-num">{{ $figures['late'] }}</strong><span>{{ __('desk.panel.glance.late') }}</span></div>
    </div>
</x-desk.panel>
