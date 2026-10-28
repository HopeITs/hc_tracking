<?php

use App\Enums\ArStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Enums\RagStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Indicator;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Support\Desk\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * At a glance — "are we OK this month?": up to six figures, each a link to
 * its register with one line of meaning and its change since last month.
 * Running projects (new this month), proposals in the pipeline (new this
 * month), last month's reports validated (against the month before), USD
 * received of committed (money viewers; received this month), late tasks
 * (against the same day last month) and targets on pace this year. A
 * change is good or bad by the figure's direction; none is shown when
 * there is nothing to compare with. Nothing to filter, so no funnel.
 *
 * attentionItems (ReadsDashboardAttention) stays on this host for the
 * tests and the Ask bar that read the dashboard queue through it.
 */
new class extends Component {
    use ChartsDashboard;
    use IsDeskPanel;
    use ReadsDashboardAttention;

    /**
     * @return list<array{key: string, value: string, label: string, delta: int|null, deltaText: string|null, good: bool|null, href: string|null}>
     */
    #[Computed]
    public function figures(): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $figures = [];

        if ($this->canView('projects')) {
            $new = $this->visible(Project::query(), 'projects')->where('status', ProjectStatus::Active)->whereDate('start_date', '>=', $monthStart)->count();
            $figures[] = $this->figure('running', (string) $this->visible(Project::query(), 'projects')->where('status', ProjectStatus::Active)->count(), $new, upIsGood: true, href: 'projects.index', deltaKey: 'new');
        }

        if ($this->canView('proposals')) {
            $pipeline = Proposal::query()->whereIn('status', [...ProposalPhase::Ideation->statuses(), ...ProposalPhase::Planning->statuses()]);
            $figures[] = $this->figure('pipeline', (string) (clone $pipeline)->count(), (clone $pipeline)->whereDate('created_at', '>=', $monthStart)->count(), upIsGood: true, href: 'proposals.index', deltaKey: 'new');
        }

        if ($this->canView('reports')) {
            $lastMonth = $monthStart->subMonth();
            $validated = fn (CarbonImmutable $month): int => MonthlyReport::query()->whereDate('period', $month)->where('status', MonthlyReportStatus::Validated)->count();
            $total = MonthlyReport::query()->whereDate('period', $lastMonth)->count();
            $figures[] = [
                ...$this->figure('reports', $validated($lastMonth).'/'.$total, $validated($lastMonth) - $validated($lastMonth->subMonth()), upIsGood: true, href: 'reports.monthly-matrix'),
                'label' => __('desk.glance.reports', ['month' => $lastMonth->translatedFormat('F')]),
            ];
        }

        $usd = $this->fundingPosition->firstWhere('currency', 'USD');

        if ($usd !== null && $usd['share'] !== null) {
            $thisMonth = (float) TransfersTracking::query()->where('ar_status', ArStatus::Received)->where('amount_received_currency', 'USD')->whereDate('receive_date', '>=', $monthStart)->sum('net_amount_received');
            $figures[] = [
                ...$this->figure('money', $usd['share'].'%', $thisMonth > 0 ? 1 : null, upIsGood: true, href: 'transfers.index'),
                'label' => __('desk.glance.money', ['amount' => Money::format($usd['committed'], 'USD')]),
                'deltaText' => $thisMonth > 0 ? __('desk.glance.delta.received', ['amount' => Money::format($thisMonth, 'USD')]) : null,
            ];
        }

        if ($this->canView('tasks')) {
            $lateOn = fn (CarbonImmutable $day): int => $this->visible(Task::query(), 'tasks')
                ->whereDate('due_date', '<', $day)
                ->whereDate('created_at', '<=', $day)
                ->where(fn (Builder $query) => $query->where('status', '!=', TaskStatus::Done)->orWhereDate('completed_date', '>', $day))
                ->count();
            $now = $this->visible(Task::query(), 'tasks')->where('status', '!=', TaskStatus::Done)->whereDate('due_date', '<', $today)->count();
            $figures[] = $this->figure('late', (string) $now, $now - $lateOn($today->subMonth()), upIsGood: false, href: 'tasks.index');
        }

        if ($this->canView('targets') && $this->canView('indicators')) {
            $yearStart = $today->startOfYear();
            $rated = Indicator::query()->whereNotNull('target')->with('progress:id,indicator_id,date,value')->get(['id', 'baseline', 'target'])
                ->map(fn (Indicator $indicator): ?RagStatus => $indicator->ragStatus($yearStart, $today->endOfYear()))
                ->filter();
            $figures[] = [
                ...$this->figure('targets', $rated->filter(fn (RagStatus $rag): bool => $rag === RagStatus::Green)->count().'/'.$rated->count(), null, upIsGood: true, href: 'targets.index'),
            ];
        }

        return $figures;
    }

    /**
     * @return array{key: string, value: string, label: string, delta: int|null, deltaText: string|null, good: bool|null, href: string|null}
     */
    private function figure(string $key, string $value, ?int $delta, bool $upIsGood, ?string $href, string $deltaKey = 'change'): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'label' => __('desk.glance.'.$key),
            'delta' => $delta,
            'deltaText' => $delta === null || $delta === 0 ? ($delta === 0 ? __('desk.glance.delta.same') : null) : __('desk.glance.delta.'.$deltaKey, ['count' => ($delta > 0 ? '+' : '−').abs($delta)]),
            'good' => $delta === null || $delta === 0 ? null : ($delta > 0) === $upIsGood,
            'href' => $href !== null && \Illuminate\Support\Facades\Route::has($href) ? route($href) : null,
        ];
    }
};
?>

@php
    $figures = $this->figures;
@endphp

<x-desk.panel id="glance" :size="$size" :index="$index" :total="$total" :filterable="false">
    <ul class="dk-glance" style="--figs:{{ max(1, count($figures)) }}">
        @foreach ($figures as $figure)
            <li data-figure="{{ $figure['key'] }}">
                @if ($figure['href'] !== null)<a href="{{ $figure['href'] }}" wire:navigate class="dk-gl-a">@else<span class="dk-gl-a">@endif
                    <strong class="dk-num">{{ $figure['value'] }}</strong>
                    <span class="dk-gl-l">{{ $figure['label'] }}</span>
                    @if ($figure['deltaText'] !== null)
                        <span @class(['dk-gl-d dk-num', 'dk-good' => $figure['good'] === true, 'dk-bad' => $figure['good'] === false]) data-trend="{{ $figure['delta'] > 0 ? 'up' : ($figure['delta'] < 0 ? 'down' : 'same') }}">@if ($figure['delta'] > 0)▲@elseif ($figure['delta'] < 0)▼@endif {{ $figure['deltaText'] }}</span>
                    @endif
                @if ($figure['href'] !== null)</a>@else</span>@endif
            </li>
        @endforeach
    </ul>
</x-desk.panel>
