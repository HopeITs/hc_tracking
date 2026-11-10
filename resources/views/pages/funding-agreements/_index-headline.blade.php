{{--
    The three headline figures of the funding agreements page: every
    agreement sorted into one of three states, each figure a filter.

    The three are disjoint and cover every agreement that passes the other
    filters, so they always add up to the figure beside the page title.
    Included by ⚡index.blade.php.
--}}
@use('App\Support\AgreementHealth')
@php
    $counts = $this->healthCounts;
    $matching = array_sum($counts);

    $headlines = [
        [
            'key' => AgreementHealth::ATTENTION,
            'label' => __('funding_agreements.health_attention'),
            'note' => __('funding_agreements.late_failed_or_an_overdue_report'),
            'tone' => 'text-status-critical-text',
            'dot' => 'bg-primary dark:bg-status-critical',
        ],
        [
            'key' => AgreementHealth::MOVING,
            'label' => __('funding_agreements.health_moving'),
            'note' => __('funding_agreements.money_scheduled_nothing_late'),
            'tone' => 'text-funding',
            'dot' => 'bg-funding',
        ],
        [
            'key' => AgreementHealth::NOT_STARTED,
            'label' => __('funding_agreements.health_not_started'),
            'note' => __('funding_agreements.no_tranche_scheduled_yet'),
            'tone' => 'text-secondary-dark dark:text-ink',
            'dot' => 'border border-dashed border-secondary-medium dark:border-ink-faint',
        ],
    ];
@endphp

<section aria-labelledby="agreement-headline-heading" class="mb-8" data-agreement-headline>
    <h2 id="agreement-headline-heading" class="sr-only">{{ __('funding_agreements.where_every_agreement_stands') }}</h2>

    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($headlines as $headline)
            @php
                $count = $counts[$headline['key']] ?? 0;
                $isActive = $this->health === $headline['key'];
                $isEmpty = $count === 0;
            @endphp
            <li>
                @php
                    // Nothing in this state: the figure still reads 0, but pressing it
                    // would only cost an empty page. It stays a tab stop all the same.
                    $isUnavailable = $isEmpty && ! $isActive;
                @endphp
                <button
                    type="button"
                    @unless ($isUnavailable)
                        wire:click="toggleHealth('{{ $headline['key'] }}')"
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
                        <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('funding_agreements.of_count_agreements', $matching, ['count' => $matching]) }}</span>
                    </span>
                    <span @class(['mt-1 block text-[11px] text-secondary-medium dark:text-ink-muted group-aria-pressed:underline', 'group-hover:underline' => ! $isUnavailable])>{{ $headline['note'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>
</section>
