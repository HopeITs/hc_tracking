<?php

use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Support\Desk\ProjectStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Every project: one line per project through its seven stages, a health
 * word and one of five lenses (stage, money, results, dates, risks).
 * Stage and health come from App\Support\Desk\ProjectStage.
 */
new class extends Component {
    use IsDeskPanel;

    public string $lens = 'stage';

    public string $scope = 'mine';

    public function setLens(string $lens): void
    {
        $this->lens = in_array($lens, ['stage', 'money', 'results', 'dates', 'risks'], true) ? $lens : 'stage';
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope === 'all' ? 'all' : 'mine';
        $this->page = 0;
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
                'proposals:id,name,status',
                'proposals.donorNames',
                'milestones' => fn ($query) => $query->select(['id', 'project_id', 'planned_end_date', 'status'])->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing]),
                'risks' => fn ($query) => $query->select(['id', 'project_id', 'name', 'happened'])->where('happened', false),
                'monthlyReports:id,project_id,period,status,returned_at',
                ...($money ? ['proposals.donorProposals:id,proposal_id,budget,currency', 'proposals.donorProposals.transfers'] : []),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * project id => derived line: stage 0–7, health, attention count and lens figures.
     *
     * @return array<int, array{stage: int, health: string, attention: int, lead: Proposal|null, donor: string, money: array{currency: string, committed: float, received: float, next: TransfersTracking|null}|null, results: array{validated: int, total: int}}>
     */
    #[Computed]
    public function lines(): array
    {
        $lines = [];

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
                    'received' => (float) $same->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'received')->sum(fn (TransfersTracking $transfer): float => (float) ($transfer->net_amount_received ?? $transfer->payment_amount)),
                    'next' => $same->filter(fn (TransfersTracking $transfer): bool => in_array($transfer->state(), ['late', 'upcoming', 'transit'], true))->sortBy(fn (TransfersTracking $transfer): string => (string) $transfer->scheduled_date?->toDateString())->first(),
                ];
            }

            $lines[$project->id] = [
                'stage' => $stage,
                'health' => ProjectStage::health($stage, $signals),
                'attention' => $signals['late'] + $signals['soon'],
                'lead' => $lead,
                'donor' => $lead?->donorNames->pluck('name')->implode(', ') ?? '',
                'money' => $money,
                'results' => ['validated' => $project->monthlyReports->where('status', MonthlyReportStatus::Validated)->count(), 'total' => $project->monthlyReports->count()],
            ];
        }

        return $lines;
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
    $pg = $this->paginate($list, 6);
    $lenses = array_filter(['stage', 'money', 'results', 'dates', 'risks'], fn (string $key): bool => $key !== 'money' || $this->canViewFundingPosition());
    $shownLens = in_array($lens, $lenses, true) ? $lens : 'stage';
    $today = CarbonImmutable::today();
    $dated = $list->filter(fn ($project) => $project->start_date !== null && $project->end_date !== null);
    $axisFrom = ($dated->min('start_date') ?? $today->subYear())->startOfMonth();
    $axisTo = ($dated->max('end_date') ?? $today->addYear())->endOfMonth();
    $span = max(1, $axisFrom->diffInDays($axisTo));
    $pos = fn ($date) => round(max(0, min(100, $axisFrom->diffInDays($date, false) / $span * 100)), 2);
    $stages = __('desk.stages');
@endphp

<x-desk.panel id="projects" register="projects.index" :size="$size" :index="$index" :total="$total" :count="$list->count()">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach ($lenses as $key)
                <button type="button" wire:click="setLens('{{ $key }}')" aria-pressed="{{ $shownLens === $key ? 'true' : 'false' }}">{{ __('desk.lens.'.$key) }}</button>
            @endforeach
        </div>
        @if ($hasOthers)
            <label class="dk-sr" for="ps-projects-scope">{{ __('desk.scope') }}</label>
            <select class="dk-mini" id="ps-projects-scope" wire:change="setScope($event.target.value)">
                <option value="mine" @selected($scope === 'mine')>{{ __('desk.scope_mine_projects') }}</option>
                <option value="all" @selected($scope === 'all')>{{ __('desk.filter_all') }}</option>
            </select>
        @endif
        <label class="dk-sr" for="ps-projects-f">{{ __('desk.filter') }}</label>
        <select class="dk-mini" id="ps-projects-f" wire:change="setFilter($event.target.value)">
            @foreach ($filters as $key => $test)
                <option value="{{ $key }}" @selected($active === $key)>{{ __('desk.project_filter.'.$key) }} ({{ $base->filter($test)->count() }})</option>
            @endforeach
        </select>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-rows">
            @foreach ($pg['slice'] as $project)
                @php
                    $line = $lines[$project->id];
                    $stage = $line['stage'];
                    $health = $line['health'];
                @endphp
                <li wire:key="project-{{ $project->id }}">
                    <span @class(['dk-att', 'dk-zero' => $line['attention'] === 0])>{{ $line['attention'] ?: '' }}</span>
                    <div class="dk-rn"><strong><a href="{{ route('projects.show', $project) }}" wire:navigate>{{ $project->name }}</a></strong><span>{{ $line['donor'] !== '' ? $line['donor'] : __('desk.no_donor_yet') }} · {{ $project->user?->name ?? '—' }}</span></div>
                    <div class="dk-thread-cell">
                        <ol class="dk-thread" aria-label="{{ __('desk.stage_of', ['stage' => min($stage, 6) + 1]) }}">
                            @foreach ($stages as $i => $word)
                                <li @class(['dk-done' => $i < $stage, 'dk-now dk-'.$health => $i === $stage]) title="{{ $word }}"><i></i></li>
                            @endforeach
                        </ol>
                        <span class="dk-stage-name">{{ $stages[min($stage, 6)] }}@if ($stage === 7) · {{ $project->status->label() }}@endif</span>
                    </div>
                    <div class="dk-rl">
                        @switch($shownLens)
                            @case('money')
                                @if ($line['money'] === null)
                                    <span class="dk-muted">{{ __('desk.no_ask_yet') }}</span>
                                @else
                                    @php
                                        $m = $line['money'];
                                        $next = $m['next'];
                                    @endphp
                                    <div class="dk-lv"><span><strong class="dk-num">{{ \App\Support\Desk\Money::format($m['received'], $m['currency']) }}</strong> {{ __('desk.of') }} <span class="dk-num">{{ \App\Support\Desk\Money::format($m['committed'], $m['currency']) }}</span></span>@if ($next !== null)<span class="{{ $next->state() === 'late' ? 'dk-health dk-bad' : 'dk-muted' }}">{{ $next->state() === 'late' ? __('desk.late_word') : __('desk.next_word') }} {{ \App\Support\Desk\Money::format($next->payment_amount, $m['currency']) }}@if ($next->scheduled_date !== null) · {{ $next->scheduled_date->translatedFormat(__('desk.date_short')) }}@endif</span>@else<span class="dk-muted">{{ __('desk.all_in') }}</span>@endif</div>
                                    <div class="dk-meter"><b style="width:{{ $m['committed'] > 0 ? min(100, round($m['received'] / $m['committed'] * 100)) : 0 }}%"></b></div>
                                @endif
                                @break
                            @case('results')
                                @if ($line['results']['total'] === 0)
                                    <span class="dk-muted">{{ __('desk.not_reporting_yet') }}</span>
                                @else
                                    @php
                                        $pct = (int) round($line['results']['validated'] / $line['results']['total'] * 100);
                                    @endphp
                                    <div class="dk-lv"><span><strong class="dk-num">{{ $line['results']['validated'] }}/{{ $line['results']['total'] }}</strong> {{ __('desk.months_validated') }}</span></div>
                                    <div class="dk-meter dk-res"><b style="width:{{ $pct }}%"></b></div>
                                @endif
                                @break
                            @case('dates')
                                <div class="dk-tl"><span class="dk-axis"></span>@if ($project->start_date !== null && $project->end_date !== null)<span @class(['dk-bar', 'dk-future' => $project->start_date->gt($today)]) style="inset-inline-start:{{ $pos($project->start_date) }}%;width:{{ max(0, $pos($project->end_date) - $pos($project->start_date)) }}%"></span>@endif<span class="dk-today" style="inset-inline-start:{{ $pos($today) }}%"></span></div>
                                @if ($project->start_date !== null && $project->end_date !== null)
                                    <div class="dk-tl-legend"><span>{{ $project->start_date->translatedFormat('M Y') }}</span><span>{{ $project->end_date->translatedFormat('M Y') }}</span></div>
                                @else
                                    <span class="dk-muted dk-small">{{ __('desk.no_dates_yet') }}</span>
                                @endif
                                @break
                            @case('risks')
                                @if ($project->risks->isEmpty())
                                    <span class="dk-muted">{{ __('desk.no_open_risks') }}</span>
                                @else
                                    <span><span class="dk-riskdots">@foreach ($project->risks as $risk)<i class="dk-med"></i>@endforeach</span>{{ $project->risks->first()->name }}</span>
                                @endif
                                @break
                            @default
                                <span>{{ $line['lead'] !== null ? $line['lead']->status->label() : $project->status->label() }}</span>
                        @endswitch
                    </div>
                    <span class="dk-hc"><span class="dk-health dk-{{ $health }}">{{ __('desk.health.'.$health) }}</span></span>
                    <x-desk.icon name="chev" class="dk-chev dk-flip" />
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
