{{--
    The three headline figures of the transfers page: every tranche in one of
    three states, each figure a filter.

    The three are disjoint and cover every tranche that passes the other
    filters, so they always add up to the figure beside the page title.
    Included by ⚡index.blade.php.
--}}
@php
    $counts = $this->focusCounts;
    $matching = array_sum($counts);

    $headlines = [
        [
            'key' => 'attention',
            'label' => __('transfers.focus_attention'),
            'note' => __('transfers.late_failed_or_unaccounted'),
            // 30px at weight 800 is large text, so status-critical's 3.75:1 on
            // the dark ground clears the 3:1 it needs.
            'tone' => 'text-status-critical',
            'dot' => 'bg-primary dark:bg-status-critical',
        ],
        [
            'key' => 'travelling',
            'label' => __('transfers.focus_travelling'),
            'note' => __('transfers.scheduled_or_on_its_way'),
            'tone' => 'text-status-warn',
            'dot' => 'bg-status-warn',
        ],
        [
            'key' => 'landed',
            'label' => __('transfers.focus_landed'),
            'note' => __('transfers.arrived_and_accounted_for'),
            'tone' => 'text-funding',
            'dot' => 'bg-funding',
        ],
    ];
@endphp

<section aria-labelledby="transfer-headline-heading" class="mb-8" data-transfer-headline>
    <h2 id="transfer-headline-heading" class="sr-only">{{ __('transfers.where_every_tranche_stands') }}</h2>

    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($headlines as $headline)
            @php
                $count = $counts[$headline['key']] ?? 0;
                $isActive = $this->focus === $headline['key'];
                // Nothing in this state: the figure still reads 0, but pressing it
                // would only cost an empty page. It stays a tab stop all the same.
                $isUnavailable = $count === 0 && ! $isActive;
            @endphp
            <li>
                <button
                    type="button"
                    @unless ($isUnavailable)
                        wire:click="toggleFocus('{{ $headline['key'] }}')"
                    @else
                        aria-disabled="true"
                        title="{{ __('finance_tables.nothing_matches_this_here') }}"
                    @endunless
                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                    @class([
                        'group flex w-full min-h-[4.5rem] flex-col justify-center rounded-xl border p-4 text-start transition-colors duration-150 touch-manipulation focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:border-line',
                        'border-secondary-soft hover:bg-board-head dark:hover:bg-white/5' => ! $isActive && ! $isUnavailable,
                        'border-secondary-dark bg-board-head dark:border-ink dark:bg-white/5' => $isActive,
                        'border-secondary-soft cursor-default opacity-60' => $isUnavailable,
                    ])
                    data-headline="{{ $headline['key'] }}"
                >
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                        <span class="size-2 shrink-0 rounded-full {{ $headline['dot'] }}" aria-hidden="true"></span>
                        {{ $headline['label'] }}
                    </span>
                    <span class="mt-1.5 flex items-baseline gap-2">
                        <span @class(['text-3xl leading-none font-extrabold tracking-tight tabular-nums', $headline['tone']])>{{ $count }}</span>
                        <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('transfers.of_count_tranches', $matching, ['count' => $matching]) }}</span>
                    </span>
                    <span @class(['mt-1 block text-[11px] text-secondary-medium dark:text-ink-muted group-aria-pressed:underline', 'group-hover:underline' => ! $isUnavailable])>{{ $headline['note'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>
</section>
