<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\RagStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\TransfersTracking;
use App\Support\Desk\DeskWork;
use App\Support\Desk\ProjectStage;
use App\Support\Locations\MonthlyReportsAtRows;
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
 *
 * Y-16 (D-93): a line is a **location row**, named by its place and
 * programme (label()) and owned by its responsible employee. A row is of
 * exactly one proposal, so that proposal is its lead and that proposal's
 * agreements are the row's money.
 */
new class extends Component
{
    use IsDeskPanel;

    public string $lens = 'dates';

    public string $scope = 'all';

    public function setLens(string $lens): void
    {
        $this->lens = in_array($lens, ['stage', 'money', 'results', 'dates'], true) ? $lens : 'dates';
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope === 'all' ? 'all' : 'mine';
        $this->page = 0;
    }

    /**
     * @return list<string>
     */
    protected function filterProperties(): array
    {
        return ['scope', 'filter'];
    }

    public function updatedScope(string $scope): void
    {
        $this->setScope($scope);
    }

    /**
     * @return Collection<int, ProposalLocation>
     */
    #[Computed]
    public function projects(): Collection
    {
        $money = $this->canViewFundingPosition();

        return DeskWork::locationsOnCard(auth()->user())
            ->select(['id', 'proposal_id', 'slug', 'status', 'start_date', 'end_date', 'city_id', 'city_wide', 'program_id', 'project_id', 'responsible_employee_id'])
            ->with([
                'city:id,name_en,name_ar',
                'program:id',
                'centers',
                'responsible',
                'proposal:id,name,status,donor_id',
                'proposal.donor:id,name',
                'proposal.donorNames',
                'indicators' => fn ($query) => $query->select(['id', 'project_id', 'proposal_location_id', 'baseline', 'target'])->whereNotNull('target'),
                'indicators.progress:id,indicator_id,date,value',
                ...($money ? ['proposal.donorProposals:id,proposal_id,budget,currency', 'proposal.donorProposals.transfers'] : []),
            ])
            ->get()
            ->sortBy(fn (ProposalLocation $row): string => $row->label())
            ->values();
    }

    /**
     * row id => derived line: stage 0–7, health, attention count and lens figures.
     *
     * @return array<int, array{stage: int, health: string, attention: int, lead: Proposal|null, donor: string, centerInactive: bool, money: array{currency: string, committed: float, received: float, next: TransfersTracking|null}|null, results: array{validated: int, total: int, onPace: int, rated: int}}>
     */
    #[Computed]
    public function lines(): array
    {
        $lines = [];
        $thisMonth = CarbonImmutable::today()->startOfMonth();
        // One read of the monthly reports at every row's place (Y-16).
        $reports = app(MonthlyReportsAtRows::class)->reportsByRow($this->projects, ['id', 'project_id', 'report_template_id', 'period', 'status', 'returned_at']);

        foreach ($this->projects as $project) {
            $project->setMonthlyReports($reports[$project->id] ?? new Collection);
            $lead = ProjectStage::leadOf($project);
            $stage = ProjectStage::stage($project, $lead);
            $signals = ProjectStage::signals($project);
            $transfers = ProjectStage::transfers($project);

            $money = null;
            $agreement = $lead?->relationLoaded('donorProposals') ? $lead->donorProposals->first() : null;

            if ($agreement !== null) {
                $currency = $agreement->currency->value;
                $same = $transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency->value === $currency);
                $money = [
                    'currency' => $currency,
                    'committed' => (float) $lead->donorProposals->filter(fn ($link): bool => $link->currency->value === $currency)->sum('budget'),
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
                'centerInactive' => $project->hasInactiveCenter(),
                'money' => $money,
                'results' => [
                    'validated' => $project->monthlyReports()->where('status', MonthlyReportStatus::Validated)->count(),
                    'total' => $project->monthlyReports()->filter(fn ($report): bool => $report->period->lt($thisMonth))->count(),
                    ...$this->targetsOnPace($project),
                ],
            ];
        }

        return $lines;
    }

    /**
     * How many of the row's targets are on pace this year (green), of
     * those that can be rated.
     *
     * @return array{onPace: int, rated: int}
     */
    private function targetsOnPace(ProposalLocation $project): array
    {
        $today = CarbonImmutable::today();
        $rated = $project->indicators->map(fn (Indicator $indicator): ?RagStatus => $indicator->ragStatus($today->startOfYear(), $today->endOfYear()))->filter();

        return ['onPace' => $rated->filter(fn (RagStatus $rag): bool => $rag === RagStatus::Green)->count(), 'rated' => $rated->count()];
    }
};
?>

@php
    $lines = $this->lines;
    ['hasOthers' => $hasOthers, 'shown' => $shownByDefault] = DeskWork::locationScopeOf($this->projects, auth()->user());
    $base = $scope === 'mine' ? $shownByDefault : $this->projects;
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
    $shownLens = in_array($lens, $lenses, true) ? $lens : 'dates';
    $today = CarbonImmutable::today();
    $stages = __('desk.stages');
@endphp

<x-desk.panel :empty="$base->isEmpty()" :facets="$pg['facets']" :filtered="$active !== 'all' || ($hasOthers && $scope !== 'all')" id="projects" :register="'projects.index'" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$list->count()">
    <x-slot:filters>
        @if ($hasOthers)
            <div class="contents" data-filter-defaults='{"scope":"all"}'>
                <x-ui.header-filter-select :label="__('desk.scope')" wire:model="scope" :value="$scope" deferred>
                    <option value="all">{{ __('desk.filter_all') }}</option>
                    <option value="mine">{{ __('desk.scope_mine_projects') }}</option>
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
                <button type="button" wire:click="setLens('{{ $key }}')" aria-pressed="{{ $shownLens === $key ? 'true' : 'false' }}" title="{{ __('desk.lens.'.$key) }}">{{ __('desk.lens.'.$key) }}</button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-pj" {{ $this->fitAttributes($pg) }} data-lens="{{ $shownLens }}">
            @foreach ($pg['slice'] as $project)
                @php
                    $line = $lines[$project->id];
                    $stage = $line['stage'];
                    $health = $line['health'];
                @endphp
                <li wire:key="project-{{ $project->id }}">
                    <div class="dk-pj-n">
                        <a href="{{ route('projects.show', $project) }}" wire:navigate title="{{ $project->headline() }} · {{ $project->label() }}">@if ($line['attention'] > 0)<span class="dk-att" title="{{ trans_choice('desk.attention_items', $line['attention'], ['count' => $line['attention']]) }}">{{ $line['attention'] }}</span>@endif{{ $project->headline() }}</a>
                        <span class="dk-is dk-num" data-project-dates>{{ $project->start_date?->translatedFormat('M Y') ?? '—' }} → {{ $project->end_date?->translatedFormat('M Y') ?? '—' }}</span>
                        <span class="dk-is" title="{{ $line['donor'] }}">{{ $line['donor'] !== '' ? $line['donor'] : __('desk.no_donor_yet') }} · {{ $project->responsible?->name ?? '—' }}</span>
                        <x-projects.center-inactive-badge :inactive="$line['centerInactive']" class="dk-is" />
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
                                @elseif ($stage >= 4 || $line['lead']?->status === \App\Enums\ProposalStatus::Approved)
                                    {{-- The donor already approved: the dates are simply missing, so say so and point to where they are set. --}}
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="dk-small dk-bad" data-dates-not-set>{{ __('desk.dates_not_set') }}</a>
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
