    <div class="grid gap-6 @5xl/directory:grid-cols-[minmax(0,1fr)_auto] @5xl/directory:items-end">
        <div class="flex min-w-0 flex-col gap-4">
            <h2 id="target-pulse-heading" class="max-w-3xl text-balance text-xl font-semibold leading-snug @3xl/directory:text-2xl">
                @if ($pulse['total'] === 0)
                    {{ __('targets.pulse_empty') }}
                @else
                    {!! __('targets.pulse_sentence', [
                        'achieved' => '<span class="tabular-nums text-status-good">'.e(number_format($pulse['achieved'])).'</span>',
                        'total' => '<span class="tabular-nums">'.e(number_format($pulse['total'])).'</span>',
                    ]) !!}
                @endif
            </h2>

            <div class="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft/60 dark:bg-surface-2" role="img" aria-label="{{ __('targets.status_breakdown') }}: {{ collect($statusMeta)->map(fn ($meta) => $meta['label'].' '.$meta['count'])->join(', ') }}">
                @foreach ($statusMeta as $statusKey => $meta)
                    @if ($meta['count'] > 0)
                        <span @class(['h-full transition-opacity duration-300 ease-out', $meta['fill'], 'opacity-25' => $status !== '' && $status !== $statusKey]) style="width: {{ $shareOf($meta['count']) }}%"></span>
                    @endif
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('targets.filter_by_achievement') }}">
                @foreach ($statusMeta as $statusKey => $meta)
                    <button
                        type="button"
                        wire:click="filterStatus('{{ $statusKey }}')"
                        aria-pressed="{{ $status === $statusKey ? 'true' : 'false' }}"
                        data-test="status-filter-{{ $statusKey }}"
                        @class([
                            'inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-full border px-3.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0' => $status === $statusKey,
                            'border-secondary-soft hover:border-secondary-light dark:border-line dark:hover:border-field-line' => $status !== $statusKey,
                        ])
                    >
                        <span aria-hidden="true" class="size-2 rounded-full {{ $meta['dot'] }}"></span>
                        {{ $meta['label'] }}
                        <span class="font-semibold tabular-nums">{{ number_format($meta['count']) }}</span>
                        <span class="text-xs tabular-nums opacity-70">{{ number_format($shareOf($meta['count'])) }}%</span>
                    </button>
                @endforeach
            </div>
        </div>

        <dl class="flex flex-wrap gap-x-10 gap-y-4 @5xl/directory:justify-end">
            <div class="flex flex-col gap-1.5">
                <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ __('targets.committed_in_plans') }}</dt>
                <dd class="text-3xl font-bold leading-none tabular-nums">{{ number_format($pulse['plans']) }}</dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ __('targets.committed_in_proposals') }}</dt>
                <dd class="text-3xl font-bold leading-none tabular-nums text-funding dark:text-ink">{{ number_format($pulse['proposals']) }}</dd>
            </div>
            <div class="flex flex-col gap-1.5">
                <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ __('targets.reports_awaiting_approval') }}</dt>
                <dd class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold leading-none tabular-nums">{{ number_format($pulse['reviewed']) }}</span>
                    @if ($pulse['drafts'] > 0)
                        <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('targets.count_draft', $pulse['drafts'], ['count' => number_format($pulse['drafts'])]) }}</span>
                    @endif
                </dd>
            </div>
        </dl>
    </div>
