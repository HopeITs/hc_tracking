{{--
    One group card on the program's Annual plans tab, built like the Overview's
    project card: name, pace and bar, three facts, then a button that opens the
    group's targets in a modal. Expects $group, $cardKey and $cardIndex; the
    page's Alpine `limit` decides how many cards show (row by row).
--}}
@php
    $pace = match (true) {
        $group['progress'] === null => null,
        $group['reached'] === $group['count'] => 'reached',
        $elapsed === 0 => 'upcoming',
        $group['progress'] + $this::PLAN_PACE_GRACE >= $elapsed => 'on',
        default => 'behind',
    };
    $paceTone = ['reached' => 'dk-good', 'on' => 'dk-good', 'behind' => 'dk-warn', 'upcoming' => ''][$pace] ?? '';
    $nextLate = $group['next'] !== null && $group['next']->isPast();
    $modalName = 'plan-targets-'.$group['key'];
@endphp
<article wire:key="{{ $cardKey }}"
    x-show="{{ $cardIndex }} < limit" @if ($cardIndex >= 1 && ! $board['searching']) x-cloak @endif
    class="dk-prog-card dk-ppl-gc" data-plan-group="{{ $group['key'] }}">

    {{-- 1. Name, and the city (projects) or how many projects it spans. --}}
    <div class="dk-prog-card-h">
        <h3 class="dk-prog-card-t" title="{{ $group['label'] }}">
            @if ($group['href'] !== null)
                <a href="{{ $group['href'] }}" wire:navigate class="dk-ppl-gc-title">{{ $group['label'] }}</a>
            @else
                {{ $group['label'] }}
            @endif
        </h3>
        <p class="dk-prog-card-o">
            @if ($group['level'] !== null)
                <span class="dk-ppl-mark {{ $levelMarks[$group['level']->value] }}" aria-hidden="true"></span>
            @elseif ($this->planGroupBy === 'status')
                <span class="dk-ppl-dot {{ $statusTones[$group['key']] }}" aria-hidden="true"></span>
            @endif
            @if ($this->planGroupBy === 'project')
                <x-desk.icon name="pin" />{{ $group['city'] ?? '—' }}
            @else
                <x-desk.icon name="folder" />{{ trans_choice('programs.plans_project_count', $group['projects'], ['count' => $group['projects']]) }}
            @endif
        </p>
    </div>

    {{-- 2. Pace and average progress: the tick marks where it should be by today. --}}
    <div class="dk-prog-card-pace">
        <div class="dk-prog-card-row">
            @if ($pace !== null)
                <span class="dk-prog-pill {{ $paceTone }}">{{ __('programs.plans_pace_'.$pace) }}</span>
            @endif
            <span class="dk-prog-card-pct dk-num">{{ $group['progress'] === null ? '—' : $group['progress'].'%' }}</span>
        </div>
        <span class="dk-prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('programs.plans_average_progress') }}" @if ($group['progress'] !== null) aria-valuenow="{{ $group['progress'] }}" @endif
            @if ($elapsed > 0 && $elapsed < 100) title="{{ __('programs.pace_expected', ['percent' => $elapsed]) }}" @endif>
            <i style="width: {{ $group['progress'] ?? 0 }}%"></i>
            @if ($elapsed > 0 && $elapsed < 100)
                <b style="inset-inline-start: {{ $elapsed }}%"></b>
            @endif
        </span>
    </div>

    {{-- 3. Three facts, always in the same cells. --}}
    <dl class="dk-prog-cells">
        <div>
            <dt>{{ __('programs.plans_filter_all') }}</dt>
            <dd>{{ __('programs.plans_reached_short', ['reached' => $group['reached'], 'count' => $group['count']]) }}</dd>
        </div>
        <div @class(['dk-bad' => $group['late'] > 0]) data-group-late="{{ $group['late'] }}">
            <dt>{{ __('programs.plans_filter_late') }}</dt>
            <dd>{{ $group['late'] > 0 ? $group['late'] : __('programs.plans_none_late') }}</dd>
        </div>
        <div @class(['dk-bad' => $nextLate])>
            <dt>{{ __('programs.plans_next_report') }}</dt>
            <dd>{{ $group['next']?->translatedFormat('M j') ?? '—' }}</dd>
        </div>
    </dl>

    {{-- 4. Opens this card's targets in a modal, pinned to the bottom. --}}
    <button type="button" class="dk-prog-card-f dk-ppl-gc-toggle" x-on:click="$flux.modal(@js($modalName)).show()" aria-haspopup="dialog" data-test="plan-show-targets">
        <span>{{ trans_choice('programs.plans_show_targets', $group['count'], ['count' => $group['count']]) }}</span>
        <span class="dk-ppl-gc-caret" aria-hidden="true"><x-desk.icon name="expand" /></span>
    </button>

    <x-ui.modal size="xl" :name="$modalName" flush
        :title="$group['label']"
        :description="trans_choice('programs.plans_target_count', $group['count'], ['count' => $group['count']]).' · '.__('programs.plans_reached_short', ['reached' => $group['reached'], 'count' => $group['count']])"
        :cancel-label="__('common.close')">
        <ul class="dk-ppl-list dk-ppl-modal" data-plan-targets-modal="{{ $group['key'] }}">
            @foreach ($group['rows'] as $row)
                @php
                    $goal = $this->formatPlanFigure($row['target'], $row['is_percent']);
                    $done = $this->formatPlanFigure($row['achieved'], $row['is_percent']);
                    $fill = $row['percent'] === null ? ($row['achieved'] > 0 ? 100 : 0) : min(100, (int) round($row['percent']));
                    $tone = match (true) {
                        $row['reached'] => 'dk-good',
                        $row['behind'] => 'dk-warn',
                        default => '',
                    };
                    $statusText = match ($row['status']) {
                        'late' => trans_choice('programs.plans_pill_late', $row['late'], ['count' => $row['late']]),
                        'review' => trans_choice('programs.plans_pill_review', $row['review'], ['count' => $row['review']]),
                        'draft' => __('programs.plans_pill_draft'),
                        'none' => __('programs.plans_pill_none'),
                        'approved' => trans_choice('programs.plans_pill_approved', $row['approved'], ['count' => $row['approved']]),
                    };
                    $targetUrl = app(\App\Notifications\SubjectLinks::class)->routeFor('indicator', $row['indicator_id']) ?? route('results.index');
                @endphp
                <li class="dk-ppl-row" wire:key="plan-target-{{ $group['key'] }}-{{ $row['id'] }}" data-plan-target="{{ $row['id'] }}">
                    <div class="dk-ppl-line">
                        <div class="dk-ppl-title">
                            <a href="{{ $targetUrl }}" wire:navigate title="{{ $row['name'] }}">{{ $row['name'] }}</a>
                            <p class="dk-ptk-meta">
                                @if ($this->planGroupBy !== 'project')
                                    <span><x-desk.icon name="folder" />{{ $row['project'] ?? __('programs.plans_program_wide') }}</span>
                                @endif
                                @if ($row['result'] !== null && $this->planGroupBy !== 'level')
                                    <span class="dk-ppl-result" title="{{ $row['result'] }}">{{ $row['result'] }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="dk-ppl-prog {{ $tone }}" title="{{ $done }} / {{ $goal }}">
                            <p>
                                <span class="dk-num"><strong>{{ $done }}</strong> / {{ $goal }}</span>
                                @if ($row['unit'] !== null)<span class="dk-ppl-unit">{{ $row['unit'] }}</span>@endif
                                @if ($row['percent'] !== null)<b class="dk-num">{{ (int) round($row['percent']) }}%</b>@endif
                            </p>
                            <span class="dk-ppl-track" style="--pace: {{ $elapsed }}%" role="img" aria-label="{{ __('programs.plans_progress_label', ['percent' => $fill, 'pace' => $elapsed]) }}"><i style="width: {{ $fill }}%"></i></span>
                        </div>

                        <div class="dk-ppl-state">
                            <span class="dk-prog-pill {{ $statusTones[$row['status']] }}" data-plan-status="{{ $row['status'] }}">{{ $statusText }}</span>
                            @if ($row['next'] !== null)
                                <span @class(['dk-ppl-next', 'dk-bad' => $row['late_days'] > 0])>
                                    {{ $row['late_days'] > 0 ? __('programs.plans_was_due') : __('programs.plans_next_report') }}
                                    <span class="dk-num">{{ $row['next']->translatedFormat('M j') }}</span>
                                </span>
                            @endif
                        </div>

                        <div class="dk-ppl-acts">
                            @if ($canReport)
                                <a href="{{ route('reports.index', ['create' => 1, 'target' => $row['id']]) }}" wire:navigate class="dk-ppl-act" data-test="plan-record-report"><x-desk.icon name="plus" /><span>{{ __('programs.plans_record_report') }}</span></a>
                            @endif
                            <a href="{{ $targetUrl }}" wire:navigate class="dk-ptk-icon-btn" title="{{ __('programs.plans_open_target') }}" aria-label="{{ __('programs.plans_open_target') }}"><x-desk.icon name="arrow" class="dk-flip" /></a>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.modal>
</article>
