{{-- The three doors: Monthly reports · My reporting duties (middle) · Donor reports. --}}
@php
    $pictures = $this->doorPictures;
    $peak = max(1, ...array_map(fn (array $month): int => $month['done'] + $month['wait'] + $month['draft'] + $month['late'], $pictures['months']));
    $today = \Carbon\CarbonImmutable::today();
    $dueText = function (?\Carbon\CarbonImmutable $due, bool $late) use ($today): string {
        if ($due === null) {
            return '';
        }

        $days = (int) abs($today->diffInDays($due));

        return match (true) {
            $due->greaterThanOrEqualTo($today) => trans_choice('active_reports.due_in_days', $days, ['count' => $days]),
            $late => trans_choice('active_reports.days_late', $days, ['count' => $days]),
            default => trans_choice('active_reports.days_waiting', $days, ['count' => $days]),
        };
    };
    $monthLabel = fn (string $month): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat(__('formats.month_short'));
    $duties = $counts['duties'];
@endphp

<p class="rt-lede">
    @if ($duties['total'] > 0)
        <strong>{{ trans_choice('active_reports.lede_waiting', $duties['total'], ['count' => $duties['total']]) }}</strong>@if ($duties['late'] > 0){{ trans_choice('active_reports.lede_late', $duties['late'], ['count' => $duties['late']]) }}@endif
        {{ __('active_reports.lede_pick') }}
    @else
        {{ __('active_reports.lede_clear') }}
    @endif
</p>

<div class="rt-doors" role="list">
    {{-- Monthly reports --}}
    <button type="button" class="rt-door" wire:click="chooseKind('monthly')" data-tracker-door="monthly" data-door-count="{{ $counts['monthly']['needs'] }}" role="listitem">
        <span class="rt-door-top">
            <span><span class="rt-door-title">{{ __('active_reports.kind_monthly') }}</span><span class="rt-door-what">{{ __('active_reports.what_monthly') }}</span></span>
            <span class="rt-door-icon"><x-desk.icon name="cal" /></span>
        </span>
        <span class="rt-door-big"><b class="dk-num">{{ $counts['monthly']['needs'] }}</b><span>{{ __('active_reports.wait_on_you') }}</span></span>
        <span class="rt-door-stats">
            <span class="is-late"><b class="dk-num">{{ $counts['monthly']['late'] }}</b> {{ __('active_reports.stat_late') }}</span>
            <span><b class="dk-num">{{ $counts['monthly']['open'] }}</b> {{ __('active_reports.stat_open') }}</span>
            <span><b class="dk-num">{{ $counts['monthly']['done'] }}</b> {{ __('active_reports.stat_done') }}</span>
        </span>
        <span class="rt-bars" aria-hidden="true">
            @foreach ($pictures['months'] as $month)
                <span class="rt-bar">
                    @foreach (['done', 'wait', 'draft', 'late'] as $state)
                        @if ($month[$state] > 0)
                            <i data-state="{{ $state }}" style="height: {{ round($month[$state] / $peak * 100, 1) }}%"></i>
                        @endif
                    @endforeach
                </span>
            @endforeach
        </span>
        <span class="rt-bar-labels" aria-hidden="true">
            @foreach ($pictures['months'] as $month)
                <span>{{ $monthLabel($month['month']) }}</span>
            @endforeach
        </span>
        <span class="rt-door-go"><span>{{ $counts['monthly']['late'] > 0 ? trans_choice('active_reports.door_monthly_late', $counts['monthly']['late'], ['count' => $counts['monthly']['late']]) : __('active_reports.door_monthly_ok') }}</span><span class="rt-arrow"><x-desk.icon name="arrow" class="dk-flip" /></span></span>
    </button>

    {{-- My reporting duties --}}
    <button type="button" class="rt-door is-duties" wire:click="chooseKind('duties')" data-tracker-door="duties" data-door-count="{{ $duties['total'] }}" role="listitem">
        <span class="rt-door-top">
            <span><span class="rt-door-title">{{ __('active_reports.kind_duties') }}</span><span class="rt-door-what">{{ __('active_reports.what_duties') }}</span></span>
            <span class="rt-door-icon"><x-desk.icon name="check" /></span>
        </span>
        <span class="rt-door-big"><b class="dk-num">{{ $duties['total'] }}</b><span>{{ __('active_reports.to_do') }}</span></span>
        <span class="rt-door-stats">
            <span class="is-late"><b class="dk-num">{{ $duties['late'] }}</b> {{ __('active_reports.stat_late') }}</span>
            <span><b class="dk-num">{{ $duties['check'] }}</b> {{ __('active_reports.stat_check') }}</span>
            <span><b class="dk-num">{{ $duties['total'] - $duties['check'] }}</b> {{ __('active_reports.stat_fill_send') }}</span>
        </span>
        <span class="rt-door-list">
            @forelse ($pictures['nextDuties'] as $line)
                <span class="rt-door-item">
                    <span class="rt-tag">{{ $line->type->label() }}</span>
                    <span class="rt-door-item-name" dir="auto">{{ $line->name }}</span>
                    <span @class(['rt-door-item-due', 'is-late' => $line->late])>{{ $dueText($line->due, $line->late) }}</span>
                </span>
            @empty
                <span class="rt-door-item"><span class="rt-door-item-name">{{ __('active_reports.caught_up') }}</span></span>
            @endforelse
        </span>
        <span class="rt-door-go"><span>{{ $duties['total'] > 3 ? trans_choice('active_reports.and_more', $duties['total'] - 3, ['count' => $duties['total'] - 3]) : __('active_reports.oldest_first') }}</span><span class="rt-arrow"><x-desk.icon name="arrow" class="dk-flip" /></span></span>
    </button>

    {{-- Donor reports --}}
    <button type="button" class="rt-door" wire:click="chooseKind('donor')" data-tracker-door="donor" data-door-count="{{ $counts['donor']['needs'] }}" role="listitem">
        <span class="rt-door-top">
            <span><span class="rt-door-title">{{ __('active_reports.kind_donor') }}</span><span class="rt-door-what">{{ __('active_reports.what_donor') }}</span></span>
            <span class="rt-door-icon"><x-desk.icon name="file" /></span>
        </span>
        <span class="rt-door-big"><b class="dk-num">{{ $counts['donor']['needs'] }}</b><span>{{ __('active_reports.wait_on_you') }}</span></span>
        <span class="rt-door-stats">
            <span class="is-late"><b class="dk-num">{{ $counts['donor']['late'] }}</b> {{ __('active_reports.stat_late') }}</span>
            <span><b class="dk-num">{{ $counts['donor']['open'] }}</b> {{ __('active_reports.stat_open') }}</span>
            <span><b class="dk-num">{{ $counts['donor']['done'] }}</b> {{ __('active_reports.stat_sent') }}</span>
        </span>
        @if (($next = $pictures['nextDonor']) !== null)
            <span class="rt-next">
                <span class="rt-leaf"><small>{{ $next->due?->translatedFormat(__('formats.month_short')) }}</small><b class="dk-num">{{ $next->due?->day }}</b></span>
                <span class="rt-next-text"><b dir="auto">{{ $next->name }}</b><span dir="auto">{{ $next->donorName }} · <span @class(['is-late' => $next->overdue])>{{ $dueText($next->due, $next->overdue) }}</span></span></span>
            </span>
        @else
            <span class="rt-next"><span class="rt-next-text"><b>{{ __('active_reports.no_donor_due') }}</b></span></span>
        @endif
        <span class="rt-door-go"><span dir="auto">{{ $next !== null ? __('active_reports.next_donor', ['donor' => $next->donorName ?? $next->context]) : __('active_reports.door_donor_ok') }}</span><span class="rt-arrow"><x-desk.icon name="arrow" class="dk-flip" /></span></span>
    </button>
</div>
