{{--
    The monthly updates of an approved plan: for every centre, each month that
    has started, with where its update stands and a way in. The project manager
    of a centre fills theirs here; everyone involved opens them to read.
--}}
@php
    $execution = $this->execution;
    $today = \Carbon\CarbonImmutable::today();
    $months = array_reverse($execution->startedMonths($today));
@endphp

<section class="ap-card" data-plan-updates>
    <div>
        <h2>{{ __('annual_plans.up.updates_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.up.updates_intro') }}</p>
    </div>
    @if ($months === [])
        <div class="ap-info is-blue" data-plan-not-started>{{ __('annual_plans.up.plan_not_started', ['date' => $execution->calendar->begin->translatedFormat(__('formats.date'))]) }}</div>
    @endif
    @foreach ($execution->projects() as $centre)
        @if ($months !== [])
            <div class="ap-grp" wire:key="centre-{{ $centre->id }}" data-centre-updates="{{ $centre->id }}">
                <h3>{{ $centre->name }}</h3>
                @foreach ($months as $month)
                    @php
                        $update = $execution->update($centre, $month);
                        $ended = $today->gte($month->addMonth());
                        $late = ($update === null || $update->status === \App\Enums\PlanUpdateStatus::Draft) && $today->gt(\App\Support\PlanCalendar::dueDateOf($month));
                    @endphp
                    <a class="ap-msg" href="{{ route('plan-updates.show', [$plan, $centre, $month->format('Y-m')]) }}" wire:navigate data-update-link="{{ $centre->id }}-{{ $month->format('Y-m') }}">
                        <span class="ap-dot">{{ $month->translatedFormat('M') }}</span>
                        <span class="ap-sp"><b>{{ $month->translatedFormat('F Y') }}</b>
                            <span class="ap-sm ap-muted" style="display: block">
                                @if ($update?->sent_at) {{ __('annual_plans.up.sent_on', ['date' => $update->sent_at->translatedFormat('j M')]) }}
                                @elseif ($ended) {{ __('annual_plans.up.due_on', ['date' => \App\Support\PlanCalendar::dueDateOf($month)->translatedFormat('j M')]) }}
                                @else {{ __('annual_plans.up.month_in_progress') }}
                                @endif
                            </span>
                        </span>
                        @if ($execution->calendar->isQuarterEnd($month))<span class="dk-pill dk-act">{{ __('annual_plans.up.quarterly') }}</span>@endif
                        @include('pages.annual-plans._update-status-pill', ['update' => $update, 'late' => $late])
                    </a>
                @endforeach
            </div>
        @endif
    @endforeach
</section>
