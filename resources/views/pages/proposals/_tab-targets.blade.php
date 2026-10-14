{{--
    The Indicators & Targets tab of proposals/show. Owned by T13, which
    replaces this file with the per-center targets grid. Until then it shows
    the under-construction notice above the existing target list. Included
    by ⚡show.blade.php, whose top PHP block defines $mutedText, $inkText,
    $hairline, $amount, $term, $chronology and $arrivals.
--}}
            <p class="mb-6 flex items-center gap-2 rounded-lg border border-secondary-soft bg-board px-3 py-2 text-sm text-secondary-medium dark:border-line dark:bg-surface-1 dark:text-ink-muted" data-under-construction="targets">
                <flux:icon.wrench-screwdriver variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                <span><span class="font-semibold text-secondary-dark dark:text-ink">{{ __('proposals.under_construction') }}</span> · {{ __('proposals.under_construction_targets') }}</span>
            </p>

            @include('pages.proposals._show-targets-tab')
