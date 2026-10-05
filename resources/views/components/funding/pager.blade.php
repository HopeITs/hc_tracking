@props(['paginator', 'perPage' => '5', 'sizes' => ['5', '10', '25'], 'scrollTo' => null])

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-6 gap-y-3') }} data-finance-pager>
    <div class="flex items-center gap-1" role="group" aria-label="{{ __('finance_tables.rows_per_page') }}">
        <span class="type-label me-1 text-secondary-medium dark:text-ink-faint">{{ __('finance_tables.rows_per_page') }}</span>
        @foreach ($sizes as $size)
            <button type="button" wire:click="$set('perPage', '{{ $size }}')" aria-pressed="{{ (string) $perPage === $size ? 'true' : 'false' }}" data-test="per-page-{{ $size }}" @class([
                'min-h-8 min-w-9 rounded-full px-2 text-xs font-semibold tabular-nums',
                'bg-secondary-dark text-white dark:bg-ink dark:text-surface-0' => (string) $perPage === $size,
                'text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2' => (string) $perPage !== $size,
            ])>{{ $size }}</button>
        @endforeach
    </div>

    <flux:pagination :paginator="$paginator" :scroll-to="$scrollTo" class="min-w-60 flex-1 text-secondary-medium! dark:text-ink-muted!" />
</div>
