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
        class="relative inline-flex size-9 cursor-pointer touch-manipulation items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark max-lg:size-11 dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white"
        data-test="tour-start-button"
    >
        <flux:icon.sparkles variant="mini" />

        <span x-show="fresh" x-cloak class="absolute end-1.5 top-1.5 flex size-2">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-primary opacity-60 motion-reduce:animate-none"></span>
            <span class="relative inline-flex size-2 rounded-full bg-primary"></span>
        </span>
    </button>
</flux:tooltip>
