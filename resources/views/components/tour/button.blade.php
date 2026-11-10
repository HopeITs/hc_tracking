{{-- Header trigger for the guided tour (x-tour.overlay). A red dot invites the
     first visit and disappears once a tour has been finished or skipped. --}}
<flux:tooltip :content="__('tour.start')" position="bottom" class="inline-flex" data-tour="help">
    <button
        type="button"
        x-data="hcTourTrigger"
        x-on:click="begin()"
        x-on:hc-tour-seen.window="fresh = false"
        aria-haspopup="dialog"
        aria-label="{{ __('tour.start') }}"
        class="relative inline-flex size-8 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium transition-colors hover:bg-white hover:text-secondary-dark hover:shadow-sm max-lg:size-11 dark:text-ink-muted dark:hover:bg-surface-3 dark:hover:text-ink dark:hover:shadow-raised"
        data-test="tour-start-button"
    >
        <flux:icon.sparkles variant="outline" class="size-4.5!" />

        <span x-show="fresh" x-cloak class="absolute end-1.5 top-1.5 inline-flex size-2 rounded-full bg-primary"></span>
    </button>
</flux:tooltip>
