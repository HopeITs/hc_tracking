@props(['active' => 0, 'name' => 'finance-filters'])

@php
    /*
     * The Funding pages' filters: one Filters button in the page header, cut
     * from the same cloth as Export and the view toggle, and a centred dialog
     * behind it. The dialog edits a draft (resources/js/finance-filters.js):
     * nothing reaches the list until "Show results", and closing it any other
     * way discards the draft. Sections hang off a thread whose dots fill for
     * every filter that is set. `active` counts the filters applied now.
     */
    $count = is_bool($active) ? (int) $active : max(0, (int) $active);
@endphp

<div class="contents" x-data="financeFilters(@js($name))">
    <x-ui.button
        icon="adjustments-horizontal"
        tone="action"
        @class(['page-action-on' => $count > 0])
        x-on:click="open()"
        aria-haspopup="dialog"
        :aria-label="__('finance_tables.filters').' · '.trans_choice('finance_tables.filters_active', $count, ['count' => $count])"
        data-page-header-filters
        data-test="open-filters"
    >
        {{ __('finance_tables.filters') }}
        @if ($count > 0)
            <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[0.6875rem] font-bold leading-5 tabular-nums text-white dark:bg-brand" data-filter-count>{{ $count }}</span>
        @endif
    </x-ui.button>

    <flux:modal
        :name="$name"
        :closable="false"
        class="filter-dialog modal-shell modal-shell-centered modal-shell-md"
    >
        <div class="flex h-full min-h-0 flex-col" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'finance-filters-title')">
            <header class="flex items-start gap-4 border-b border-secondary-soft px-6 pb-4 pt-5 dark:border-line">
                <span class="mt-0.5 inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-secondary-soft/60 text-secondary-dark dark:bg-surface-2 dark:text-ink" aria-hidden="true">
                    <flux:icon.adjustments-horizontal class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <h2 id="finance-filters-title" class="text-lg font-bold leading-tight text-secondary-dark dark:text-ink">{{ __('finance_tables.filters') }}</h2>
                    <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ __('finance_tables.filters_draft_hint') }}</p>
                </div>

                <button type="button" class="-me-2 inline-flex size-9 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink" x-on:click="dismiss()" aria-label="{{ __('common.close') }}">
                    <flux:icon.x-mark class="size-5" />
                </button>
            </header>

            <div {{ $attributes->class('filter-thread min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-6') }} data-finance-filters>
                {{ $slot }}
            </div>

            <footer class="flex items-center gap-3 border-t border-secondary-soft bg-secondary-soft/25 px-6 py-4 dark:border-line dark:bg-surface-2/40">
                <div class="min-w-0 flex-1">
                    <button type="button" x-on:click="clearAll()" class="inline-flex items-center gap-1.5 text-sm font-semibold text-secondary-dark underline decoration-secondary-light underline-offset-4 transition-colors hover:decoration-secondary-dark dark:text-ink dark:decoration-line-strong dark:hover:decoration-ink" data-test="reset-filters">
                        <flux:icon.arrow-uturn-left variant="micro" class="size-4" />
                        {{ __('finance_tables.filters_clear_all') }}
                    </button>
                </div>

                <x-ui.button tone="action" type="button" x-on:click="dismiss()" data-test="cancel-filters">
                    {{ __('common.cancel') }}
                </x-ui.button>

                <x-ui.button tone="primary" type="button" class="page-action page-action-primary" x-on:click="apply()" data-test="apply-filters">
                    {{ __('finance_tables.filters_apply') }}
                </x-ui.button>
            </footer>
        </div>
    </flux:modal>
</div>
