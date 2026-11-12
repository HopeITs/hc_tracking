{{--
    The live plan (2026-11-11): every activity with its plan line and one
    execution row per centre, week by week, coloured by what the centre
    reported in sent updates. Units held against the plan, each centre's
    status, the reasons given, and a "needs attention" filter. A project
    manager or MEAL officer sees only their own centre.
--}}
@php
    $execution = $this->execution;
    $calendar = $execution->calendar;
    $centres = $this->liveCentres;
    $mondays = $calendar->mondays();
    $weekCount = max(1, count($mondays));
    $thisWeek = \Carbon\CarbonImmutable::today()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $tones = ['done' => 'dk-good', 'on_track' => 'dk-act', 'late' => 'dk-bad', 'cancelled' => 'dk-bad', 'suspended' => 'dk-warn', 'not_started' => ''];
    $statusText = fn (array $status): string => $status['key'] === 'late' ? __('annual_plans.live.late_weeks', ['weeks' => $status['weeks']]) : __('annual_plans.live.status_'.$status['key']);
    $items = $plan->items->filter(function ($item) use ($centres, $execution) {
        if (! $this->attentionOnly) {
            return true;
        }

        foreach ($centres as $centre) {
            if (in_array($execution->status($centre, $item)['key'], ['late', 'suspended', 'cancelled'], true)) {
                return true;
            }
        }

        return false;
    });
    $counts = ['done' => 0, 'on_track' => 0, 'late' => 0, 'suspended' => 0];
    foreach ($plan->items as $item) {
        foreach ($centres as $centre) {
            if (! in_array(\App\Support\MealCoverage::cityIdOf($centre), $item->cityIds(), true)) { continue; }
            $key = $execution->status($centre, $item)['key'];
            if ($key === 'cancelled') { $key = 'late'; }
            if (isset($counts[$key])) { $counts[$key]++; }
        }
    }
    $people = ['m' => 0, 'f' => 0, 'd' => 0, 'month' => null];
    foreach ($centres as $centre) {
        $latest = $execution->updates()->filter(fn ($u) => $u->project_id === $centre->id && $u->status->isOnThePlan())->sortByDesc('month')->first();
        if ($latest) { $people['m'] += (int) $latest->males; $people['f'] += (int) $latest->females; $people['d'] += (int) $latest->with_disability; $people['month'] = $latest->month; }
    }
@endphp

<section class="ap-card" data-live-plan>
    <div class="ap-row">
        <div class="ap-sp"><h2>{{ __('annual_plans.live.title') }}</h2><p class="ap-sm ap-muted">{{ __('annual_plans.live.intro') }}</p></div>
        @if (count($centres) < count($execution->projects()))<span class="dk-pill">{{ __('annual_plans.live.own_centre_only') }}</span>@endif
    </div>
    <div class="ap-stats">
        <div class="ap-stat"><b style="color: var(--good)">{{ $plan->items->filter(fn ($item) => $execution->isDoneEverywhere($item))->count() }}</b><span>{{ __('annual_plans.live.stat_done') }}</span></div>
        <div class="ap-stat"><b style="color: var(--active)">{{ $counts['on_track'] }}</b><span>{{ __('annual_plans.live.stat_on_track') }}</span></div>
        <div class="ap-stat"><b style="color: var(--bad)">{{ $counts['late'] }}</b><span>{{ __('annual_plans.live.stat_late') }}</span></div>
        <div class="ap-stat"><b style="color: var(--warn)">{{ $counts['suspended'] }}</b><span>{{ __('annual_plans.live.stat_suspended') }}</span></div>
        <div class="ap-stat"><b>{{ $people['m'] + $people['f'] }}</b><span>{{ __('annual_plans.live.stat_people', ['month' => $people['month']?->translatedFormat('F') ?? '—', 'm' => $people['m'], 'f' => $people['f'], 'd' => $people['d']]) }}</span></div>
    </div>
    <div class="ap-legend">
        <span><i class="ap-lg is-plan"></i>{{ __('annual_plans.live.planned_week') }}</span>
        @foreach (\App\Enums\PlanWeekStatus::cases() as $case)<span><i class="ap-lg is-{{ $case->value }}"></i>{{ $case->label() }}</span>@endforeach
        <span><i class="ap-lg is-off-dot"></i>{{ __('annual_plans.live.off_plan') }}</span>
        <span><i class="ap-lg is-today"></i>{{ __('annual_plans.live.this_week') }}</span>
    </div>
    <div class="ap-row"><button type="button" class="dk-chip" wire:click="$toggle('attentionOnly')" aria-pressed="{{ $this->attentionOnly ? 'true' : 'false' }}" data-test="attention-filter">{{ $this->attentionOnly ? __('annual_plans.live.attention_on') : __('annual_plans.live.attention') }}</button></div>

    <div class="ap-acts">
        @forelse ($items as $item)
            @php
                $itemCentres = array_values(array_filter($centres, fn ($centre) => in_array(\App\Support\MealCoverage::cityIdOf($centre), $item->cityIds(), true)));
                $total = (int) $item->batches->sum('planned_count') * count($itemCentres);
                $held = array_sum(array_map(fn ($centre) => $execution->held($centre, $item), $itemCentres));
                $expected = array_sum(array_map(fn ($centre) => $execution->plannedToDate($centre, $item), $itemCentres));
                $pct = fn (int $value): float => $total > 0 ? min(100, $value / $total * 100) : 0;
            @endphp
            <details class="ap-trk" wire:key="live-{{ $item->id }}" data-live-item="{{ $item->id }}">
                <summary class="ap-trk-h">
                    <span class="ap-sp"><b dir="auto">{{ $item->activity?->statement }}</b><span class="ap-xs ap-faint" style="display: block">{{ $item->is_single_week ? trans_choice('annual_plans.wf.single_weeks', $item->batches->count(), ['count' => $item->batches->count()]) : trans_choice('annual_plans.wf.batches', $item->batches->count(), ['count' => $item->batches->count()]) }} · {{ $item->counted((int) $item->batches->sum('planned_count')) }} {{ __('annual_plans.live.per_centre') }}@if ($execution->isDoneEverywhere($item)) · <b style="color: var(--good)">{{ __('annual_plans.live.done_everywhere') }}</b>@endif</span></span>
                    @foreach ($itemCentres as $centre)
                        @php $status = $execution->status($centre, $item); @endphp
                        <span class="dk-pill {{ $tones[$status['key']] }}" title="{{ $centre->name }}" data-centre-status="{{ $status['key'] }}">{{ $centre->name }}: {{ $statusText($status) }}</span>
                    @endforeach
                </summary>
                <div class="ap-meter">
                    <span>{!! __('annual_plans.live.held_of', ['held' => '<b>'.$held.'</b>', 'total' => $item->counted($total)]) !!}@if ($held < $expected - 1) · <b style="color: var(--bad)">{{ __('annual_plans.live.behind', ['n' => $expected - $held]) }}</b>@endif</span>
                    <div class="ap-bar"><i style="width: {{ $pct($held) }}%"></i><s style="inset-inline-start: {{ $pct($expected) }}%" title="{{ __('annual_plans.live.expected_by_now') }}"></s></div>
                </div>
                <div class="ap-weeks" style="--ap-n: {{ $weekCount }}">
                    <div class="ap-wrow"><span></span><div class="ap-mlab">@foreach ($this->timeline([], false)['months'] as $month)<span style="inset-inline-start: {{ $month['left'] }}%">{{ $month['label'] }}</span>@endforeach</div></div>
                    <div class="ap-wrow"><b>{{ __('annual_plans.live.plan_row') }}</b><div class="ap-wline">@foreach ($mondays as $monday)@php $isPlanned = $item->batches->contains(fn ($b) => $monday->betweenIncluded($b->start_week, $b->end_week)); @endphp<i class="{{ $isPlanned ? 'is-plan' : '' }} {{ $monday->equalTo($thisWeek) ? 'is-today' : '' }}" title="{{ $monday->translatedFormat('j M') }}"></i>@endforeach</div></div>
                    @foreach ($itemCentres as $centre)
                        @php
                            $entries = collect($execution->entriesFor($centre, $item))->groupBy(fn ($row) => $row['week']->toDateString());
                            $last = $execution->lastReportedMonday($centre);
                        @endphp
                        <div class="ap-wrow"><span dir="auto">{{ $centre->name }}</span><div class="ap-wline">
                            @foreach ($mondays as $monday)
                                @php
                                    $rows = $entries->get($monday->toDateString(), collect());
                                    $isPlanned = $item->batches->contains(fn ($b) => $monday->betweenIncluded($b->start_week, $b->end_week));
                                    $top = $rows->sortBy(fn ($row) => $row['entry']->status->rank())->first();
                                    $reported = $last !== null && $monday->lte($last);
                                    $offPlan = $reported && (($isPlanned && ($top === null || ! $top['entry']->status->isActive())) || (! $isPlanned && $top !== null && $top['entry']->status->isActive()));
                                @endphp
                                <i class="{{ ! $reported ? 'is-future' : ($top ? 'is-'.$top['entry']->status->value : '') }} {{ $offPlan ? 'is-off' : '' }} {{ $monday->equalTo($thisWeek) ? 'is-today' : '' }}" title="{{ $centre->name }} · {{ $monday->translatedFormat('j M') }}{{ $top ? ' · '.$top['entry']->status->label() : '' }}"></i>
                            @endforeach
                        </div></div>
                    @endforeach
                </div>
                @php $reasons = collect($itemCentres)->flatMap(fn ($centre) => collect($execution->entriesFor($centre, $item))->filter(fn ($row) => filled($row['entry']->reason))->map(fn ($row) => [$centre, $row])); @endphp
                @if ($reasons->isNotEmpty())
                    <div class="ap-notes"><b class="ap-sm">{{ __('annual_plans.live.reasons') }}</b>
                        @foreach ($reasons as [$centre, $row])
                            <span class="ap-muted" dir="auto">{{ $centre->name }} · {{ __('annual_plans.wf.week_of', ['date' => $row['week']->translatedFormat('j M')]) }} · {{ $row['entry']->status->label() }}@if ($row['entry']->new_week) → {{ $row['entry']->new_week->translatedFormat('j M') }}@endif: “{{ $row['entry']->reason }}”</span>
                        @endforeach
                    </div>
                @endif
            </details>
        @empty
            <p class="ap-faint ap-sm">{{ __('annual_plans.live.nothing') }}</p>
        @endforelse
    </div>
</section>
