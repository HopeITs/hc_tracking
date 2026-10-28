<?php

use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\RagStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Support\Desk\ProjectStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Every project: one row per project with its health and one of four
 * lenses, each showing something different at every size. Stage: the
 * seven-step thread with the current stage named. Money (money viewers):
 * received of committed in the lead agreement's currency, counting only
 * money that arrived in that currency, and the next tranche. Results:
 * validated of due monthly reports and targets on pace this year. Dates:
 * start → end with a today marker and days left. Stage and health come
 * from App\Support\Desk\ProjectStage.
 */
new class extends Component {
    use IsDeskPanel;

    public string $lens = 'stage';

    public string $scope = 'mine';

    public function setLens(string $lens): void
    {
        $this->lens = in_array($lens, ['stage', 'money', 'results', 'dates'], true) ? $lens : 'stage';
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope === 'all' ? 'all' : 'mine';
        $this->page = 0;
    }

    public function updatedScope(string $scope): void
    {
        $this->setScope($scope);
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        $money = $this->canViewFundingPosition();

        return $this->visible(Project::query(), 'projects')
            ->select(['id', 'slug', 'name', 'status', 'start_date', 'end_date', 'user_id'])
            ->where('status', '!=', ProjectStatus::Archived)
            ->with([
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'proposals:id,name,status,donor_id',
                'proposals.donor:id,name',
                'proposals.donorNames',
                'milestones' => fn ($query) => $query->select(['id', 'project_id', 'planned_end_date', 'status'])->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing]),
                'monthlyReports:id,project_id,period,status,returned_at',
                'indicators' => fn ($query) => $query->select(['id', 'project_id', 'baseline', 'target'])->whereNotNull('target'),
                'indicators.progress:id,indicator_id,date,value',
                ...($money ? ['proposals.donorProposals:id,proposal_id,budget,currency', 'proposals.donorProposals.transfers'] : []),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * project id => derived line: stage 0–7, health, attention count and lens figures.
     *
     * @return array<int, array{stage: int, health: string, attention: int, lead: Proposal|null, donor: string, money: array{currency: string, committed: float, received: float, next: TransfersTracking|null}|null, results: array{validated: int, total: int, onPace: int, rated: int}}>
     */
    #[Computed]
    public function lines(): array
    {
        $lines = [];
        $thisMonth = CarbonImmutable::today()->startOfMonth();

        foreach ($this->projects as $project) {
            $lead = ProjectStage::lead($project->proposals);
            $stage = ProjectStage::stage($project, $lead);
            $signals = ProjectStage::signals($project);
            $transfers = ProjectStage::transfers($project);

            $money = null;
            $agreement = $lead?->relationLoaded('donorProposals') ? $project->proposals->flatMap->donorProposals->first() : null;

            if ($agreement !== null) {
                $currency = $agreement->currency->value;
                $same = $transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency->value === $currency);
                $money = [
                    'currency' => $currency,
                    'committed' => (float) $project->proposals->flatMap->donorProposals->filter(fn ($link): bool => $link->currency->value === $currency)->sum('budget'),
                    'received' => (float) $transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'received' && $transfer->amount_received_currency?->value === $currency)->sum(fn (TransfersTracking $transfer): float => (float) ($transfer->net_amount_received ?? $transfer->amount_received ?? 0)),
                    'next' => $same->filter(fn (TransfersTracking $transfer): bool => in_array($transfer->state(), ['late', 'upcoming', 'transit'], true))->sortBy(fn (TransfersTracking $transfer): string => (string) $transfer->scheduled_date?->toDateString())->first(),
                ];
            }

            $lines[$project->id] = [
                'stage' => $stage,
                'health' => ProjectStage::health($stage, $signals),
                'attention' => $signals['late'] + $signals['soon'],
                'lead' => $lead,
                'donor' => $lead?->donor?->name ?? $lead?->donorNames->pluck('name')->implode(', ') ?? '',
                'money' => $money,
                'results' => [
                    'validated' => $project->monthlyReports->where('status', MonthlyReportStatus::Validated)->count(),
                    'total' => $project->monthlyReports->filter(fn ($report): bool => $report->period->lt($thisMonth))->count(),
                    ...$this->targetsOnPace($project),
                ],
            ];
        }

        return $lines;
    }

    /**
     * How many of the project's targets are on pace this year (green), of
     * those that can be rated.
     *
     * @return array{onPace: int, rated: int}
     */
    private function targetsOnPace(Project $project): array
    {
        $today = CarbonImmutable::today();
        $rated = $project->indicators->map(fn (Indicator $indicator): ?RagStatus => $indicator->ragStatus($today->startOfYear(), $today->endOfYear()))->filter();

        return ['onPace' => $rated->filter(fn (RagStatus $rag): bool => $rag === RagStatus::Green)->count(), 'rated' => $rated->count()];
    }
};
?>

@php
    $lines = $this->lines;
    $mine = $this->projects->where('user_id', auth()->user()->employee_id ?? 0);
    $hasOthers = $mine->isNotEmpty() && $mine->count() !== $this->projects->count();
    $base = $hasOthers && $scope === 'mine' ? $mine : $this->projects;
    $filters = [
        'all' => fn ($project): bool => true,
        'attention' => fn ($project): bool => $lines[$project->id]['health'] !== 'good' && $lines[$project->id]['stage'] < 7,
        'fund' => fn ($project): bool => $lines[$project->id]['stage'] <= 3,
        'run' => fn ($project): bool => in_array($lines[$project->id]['stage'], [4, 5], true),
        'close' => fn ($project): bool => $lines[$project->id]['stage'] >= 6,
    ];
    $active = array_key_exists($filter, $filters) ? $filter : 'all';
    $order = ['bad' => 0, 'warn' => 1, 'good' => 2];
    $list = $base->filter($filters[$active])
        ->sortBy([fn ($a, $b) => $order[$lines[$a->id]['health']] <=> $order[$lines[$b->id]['health']], fn ($a, $b) => $lines[$a->id]['stage'] <=> $lines[$b->id]['stage']])
        ->values();
    $pg = $this->paginate($list, self::PerPage);
    $lenses = array_filter(['stage', 'money', 'results', 'dates'], fn (string $key): bool => $key !== 'money' || $this->canViewFundingPosition());
    $shownLens = in_array($lens, $lenses, true) ? $lens : 'stage';
    $today = CarbonImmutable::today();
    $stages = __('desk.stages');
@endphp

<x-desk.panel :facets="$pg['facets']" :filtered="$active !== 'all' || ($hasOthers && $scope !== 'mine')" id="projects" :register="$shownLens === 'dates' ? 'timeline.index' : 'projects.index'" :size="$size" :index="$index" :total="$total" :count="$list->count()">
    <x-slot:filters>
        @if ($hasOthers)
            <div class="contents" data-filter-defaults='{"scope":"mine"}'>
                <x-ui.header-filter-select :label="__('desk.scope')" wire:model="scope" :value="$scope" deferred>
                    <option value="mine">{{ __('desk.scope_mine_projects') }}</option>
                    <option value="all">{{ __('desk.filter_all') }}</option>
                </x-ui.header-filter-select>
            </div>
        @endif
        <div class="contents" data-filter-defaults='{"filter":"all"}'>
            <x-ui.header-filter-select :label="__('desk.filter')" wire:model="filter" :value="$active" deferred>
                @foreach ($filters as $key => $test)
                    <option value="{{ $key }}">{{ __('desk.project_filter.'.$key) }} ({{ $base->filter($test)->count() }})</option>
                @endforeach
            </x-ui.header-filter-select>
        </div>
    </x-slot:filters>

    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach ($lenses as $key)
                <button type="button" wire:click="setLens('{{ $key }}')" aria-pressed="{{ $shownLens === $key ? 'true' : 'false' }}">{{ __('desk.lens.'.$key) }}</button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-pj" data-lens="{{ $shownLens }}">
            @foreach ($pg['slice'] as $project)
                @php
                    $line = $lines[$project->id];
                    $stage = $line['stage'];
                    $health = $line['health'];
                @endphp
                <li wire:key="project-{{ $project->id }}">
                    <div class="dk-pj-n">
                        <a href="{{ route('projects.show', $project) }}" wire:navigate title="{{ $project->name }}">@if ($line['attention'] > 0)<span class="dk-att" title="{{ trans_choice('desk.attention_items', $line['attention'], ['count' => $line['attention']]) }}">{{ $line['attention'] }}</span>@endif{{ $project->name }}</a>
                        <span class="dk-is" title="{{ $line['donor'] }}">{{ $line['donor'] !== '' ? $line['donor'] : __('desk.no_donor_yet') }} · {{ $project->user?->name ?? '—' }}</span>
                    </div>
                    <div class="dk-pj-l">
                        @switch($shownLens)
                            @case('money')
                                @if ($line['money'] === null)
                                    <span class="dk-muted dk-small">{{ __('desk.no_ask_yet') }}</span>
                                @else
                                    @php
                                        $m = $line['money'];
                                        $next = $m['next'];
                                    @endphp
                                    <div class="dk-meter"><b style="width:{{ $m['committed'] > 0 ? min(100, round($m['received'] / $m['committed'] * 100)) : 0 }}%"></b></div>
                                    <span class="dk-pj-v dk-num"><strong>{{ \App\Support\Desk\Money::format($m['received'], $m['currency']) }}</strong> {{ __('desk.of') }} {{ \App\Support\Desk\Money::format($m['committed'], $m['currency']) }}@if ($next !== null) · <span @class(['dk-bad' => $next->state() === 'late'])>{{ $next->state() === 'late' ? __('desk.late_word') : __('desk.next_word') }} {{ \App\Support\Desk\Money::format($next->payment_amount, $m['currency']) }}@if ($next->scheduled_date !== null) {{ $next->scheduled_date->translatedFormat(__('desk.date_short')) }}@endif</span>@else · {{ __('desk.all_in') }}@endif</span>
                                @endif
                                @break
                            @case('results')
                                @php
                                    $r = $line['results'];
                                @endphp
                                <span class="dk-pj-res">
                                    <span><b class="dk-num">{{ $r['validated'] }}/{{ $r['total'] }}</b> {{ __('desk.months_validated') }}</span>
                                    <span><b @class(['dk-num', 'dk-bad' => $r['rated'] > 0 && $r['onPace'] * 2 < $r['rated']])>{{ $r['rated'] === 0 ? '—' : $r['onPace'].'/'.$r['rated'] }}</b> {{ __('desk.targets_on_pace') }}</span>
                                </span>
                                @break
                            @case('dates')
                                @if ($project->start_date !== null && $project->end_date !== null)
                                    @php
                                        $left = (int) $today->diffInDays($project->end_date, false);
                                        $length = max(1, $project->start_date->diffInDays($project->end_date));
                                        $at = round(max(0, min(100, $project->start_date->diffInDays($today, false) / $length * 100)), 1);
                                    @endphp
                                    <div class="dk-pj-tl" title="{{ $project->start_date->translatedFormat(__('formats.date')) }} → {{ $project->end_date->translatedFormat(__('formats.date')) }}"><b style="width:{{ $at }}%"></b><i style="inset-inline-start:{{ $at }}%"></i></div>
                                    <span class="dk-pj-v dk-num">{{ $project->start_date->translatedFormat('M Y') }} → {{ $project->end_date->translatedFormat('M Y') }} · <span @class(['dk-bad' => $left < 0])>{{ $left < 0 ? trans_choice('desk.days_late', -$left, ['count' => -$left]) : trans_choice('desk.days_left', $left, ['count' => $left]) }}</span></span>
                                @else
                                    <span class="dk-muted dk-small">{{ __('desk.no_dates_yet') }}</span>
                                @endif
                                @break
                            @default
                                <ol class="dk-thread" aria-label="{{ __('desk.stage_of', ['stage' => min($stage, 6) + 1]) }}">
                                    @foreach ($stages as $i => $word)
                                        <li @class(['dk-done' => $i < $stage, 'dk-now dk-'.$health => $i === $stage]) title="{{ $word }}"><i></i></li>
                                    @endforeach
                                </ol>
                                <span class="dk-pj-v">{{ $stages[min($stage, 6)] }}@if ($stage === 7) · {{ $project->status->label() }}@endif</span>
                        @endswitch
                    </div>
                    <span class="dk-pj-h"><span class="dk-health dk-{{ $health }}">{{ __('desk.health.'.$health) }}</span></span>
                </li>
            @endforeach
        </ol>
    @else
        <p class="dk-pempty">{{ __('desk.panel.projects.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
