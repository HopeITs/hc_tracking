{{--
    The one answer at the top of targets/index: how many committed targets have
    been reached, in a sentence that names its denominator, and three buttons
    (nothing approved yet, in progress, achieved) that narrow the list; pressing
    the active one shows everything again. Included by ⚡index.blade.php, whose
    top PHP block defines $pulse, $statusMeta and $filtersAreActive.
--}}
<div class="flex flex-col gap-3" data-test="target-status-tabs">
    <h2 id="target-pulse-heading" class="max-w-3xl text-balance text-lg font-semibold leading-snug @3xl/directory:text-xl">
        @if ($pulse['total'] === 0)
            {{ __('targets.pulse_empty') }}
        @else
            {!! __('targets.pulse_sentence', [
                'achieved' => '<span class="tabular-nums text-status-good">'.e(number_format($pulse['achieved'])).'</span>',
                'total' => '<span class="tabular-nums">'.e(number_format($pulse['total'])).'</span>',
            ]) !!}
        @endif
    </h2>

    <div class="flex flex-wrap items-stretch gap-2" role="group" aria-label="{{ __('targets.filter_by_achievement') }}">
        @foreach (['not_started', 'in_progress', 'achieved'] as $statusKey)
            @php($meta = $statusMeta[$statusKey])
            <button
                type="button"
                wire:click="filterStatus('{{ $statusKey }}')"
                wire:key="status-tab-{{ $statusKey }}"
                aria-pressed="{{ $status === $statusKey ? 'true' : 'false' }}"
                data-test="status-filter-{{ $statusKey }}"
                @class([
                    'flex min-h-14 flex-1 basis-40 cursor-pointer items-center gap-3 rounded-xl border px-4 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                    'border-secondary-dark bg-white shadow-card ring-1 ring-secondary-dark dark:border-ink dark:bg-surface-2 dark:ring-ink' => $status === $statusKey,
                    'border-secondary-soft bg-white hover:border-secondary-light dark:border-line dark:bg-surface-1' => $status !== $statusKey,
                ])
            >
                <span class="text-2xl font-bold leading-none tabular-nums {{ $meta['text'] }}">{{ number_format($meta['count']) }}</span>
                <span class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $meta['label'] }}</span>
                {{-- The pressed button says it can be pressed again to show everything. --}}
                @if ($status === $statusKey)
                    <span class="ms-auto inline-flex size-6 shrink-0 items-center justify-center rounded-full text-secondary-medium dark:text-ink-muted" title="{{ __('targets.status_press_again') }}" data-test="status-filter-clear-{{ $statusKey }}">
                        <flux:icon.x-mark variant="micro" class="size-4" aria-hidden="true" />
                        <span class="sr-only">{{ __('targets.status_press_again') }}</span>
                    </span>
                @endif
            </button>
        @endforeach

        @if ($filtersAreActive)
            <flux:button size="sm" variant="ghost" class="self-center text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters" data-test="reset-filters">{{ __('common.reset_filters') }}</flux:button>
        @endif
    </div>

    @if (isset($statusMeta[$status]))
        <p class="type-meta flex flex-wrap items-center gap-x-2 text-secondary-medium dark:text-ink-muted" aria-live="polite" data-test="status-showing">
            <span>{{ __('targets.status_showing', ['shown' => number_format($statusMeta[$status]['count']), 'total' => number_format($pulse['total'])]) }}</span>
            <button type="button" wire:click="filterStatus('{{ $status }}')" class="cursor-pointer font-semibold text-secondary-dark underline underline-offset-2 dark:text-ink" data-test="status-show-all">{{ __('targets.status_show_all') }}</button>
        </p>
    @endif

    @if ($pulse['reviewed'] > 0)
        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ trans_choice('targets.count_waiting_for_approval', $pulse['reviewed'], ['count' => number_format($pulse['reviewed'])]) }}</p>
    @endif
</div>
