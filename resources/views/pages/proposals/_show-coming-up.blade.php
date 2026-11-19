{{--
    "Coming up": what is next, month by month (late items live in Needs
    attention). The first month shows open, later months fold. Reads the
    page's deadlines() so it agrees with the attention bar.
--}}
@php
    $comingItems = array_values(array_filter($this->deadlines, fn (array $item): bool => $item['days'] >= 0));
    $comingMonths = collect($comingItems)->groupBy(fn (array $item): string => $item['date']->format('Y-m'));
    $comingKind = [
        'transfer' => __('proposals.deadline_kind_transfer'),
        'donor-report' => __('proposals.deadline_kind_donor_report'),
        'report' => __('proposals.deadline_kind_report'),
        'task' => __('proposals.deadline_kind_task'),
        'project' => __('proposals.deadline_kind_project'),
        'grant' => __('proposals.deadline_kind_grant'),
    ];
@endphp

@if ($comingItems === [])
    <div class="pp-empty" data-deadlines>{{ __('proposals.sections.coming_none') }}</div>
@else
    <p class="pp-small">{{ __('proposals.sections.coming_intro') }}</p>
    <div class="pp-months" data-deadlines>
        @foreach ($comingMonths as $month => $items)
            <details class="pp-sub" wire:ignore.self @if ($loop->first) open @endif wire:key="month-{{ $month }}" data-coming-month="{{ $month }}">
                <summary>
                    <span class="pp-grow">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat(__('formats.month_year')) }}</span>
                    <span class="pp-small">{{ trans_choice('proposals.sections.n_items', $items->count(), ['count' => $items->count()]) }}</span>
                    <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="pp-sub-b">
                    @foreach ($items as $item)
                        <div class="pp-lrow" wire:key="coming-{{ $item['key'] }}" data-deadline="{{ $item['key'] }}">
                            <span class="pp-lrow-s">
                                @if ($item['url'] !== null)
                                    <a href="{{ $item['url'] }}" wire:navigate dir="auto"><b>{{ $item['title'] }}</b></a>
                                @else
                                    <b dir="auto">{{ $item['title'] }}</b>
                                @endif
                                <span class="pp-small">{{ $item['date']->translatedFormat(__('formats.date')) }} · {{ $item['days'] === 0 ? __('proposals.attention.due_today') : trans_choice('proposals.attention.in_days', $item['days'], ['count' => $item['days']]) }} · {{ $item['detail'] }}</span>
                            </span>
                            <span class="pp-pill">{{ $comingKind[$item['kind']] }}</span>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
@endif
