@props([
    /* The sentence that says how many targets are involved. */
    'body',
    /* Why some cannot be deleted (they have reports), or null. */
    'reported' => null,
    /* Why this user cannot delete them (only MEAL can), or null. */
    'noRight' => null,
    /* The proposal's targets tab; opens in a new tab so the dialog's form keeps its edits. */
    'url',
])

{{-- Y-10: the targets a remove, unlink or delete falls on, said before anything goes. --}}
<div class="grid gap-2 rounded-lg border border-secondary-light bg-board p-3 text-sm dark:border-line dark:bg-surface-2" data-targets-notice>
    <p class="flex items-start gap-2 text-secondary-dark dark:text-ink">
        <flux:icon.flag variant="mini" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <span>{{ $body }}</span>
    </p>

    @if ($reported !== null)
        <p class="flex items-start gap-2 text-status-critical" data-targets-notice-reported>
            <flux:icon.lock-closed variant="mini" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ $reported }}</span>
        </p>
    @endif

    @if ($noRight !== null)
        <p class="text-secondary-medium dark:text-ink-muted" data-targets-notice-no-right>{{ $noRight }}</p>
    @endif

    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener"
        class="inline-flex w-fit items-center gap-1 font-semibold text-primary underline-offset-2 hover:underline dark:text-brand-text"
        data-test="open-targets"
    >
        {{ __('proposals.open_targets') }}
        <flux:icon.arrow-top-right-on-square variant="micro" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
    </a>
</div>
