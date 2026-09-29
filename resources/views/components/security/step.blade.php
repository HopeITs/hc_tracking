@props(['title', 'state', 'on' => false, 'last' => false])

{{--
    One step of the sign-in thread on the security page: a dot (filled when
    the step is on) joined to the next step by a dotted line.
--}}
<li {{ $attributes->class('relative grid grid-cols-[1.5rem_minmax(0,1fr)] gap-x-4') }}>
    @unless ($last)
        <span aria-hidden="true" class="absolute start-[11px] top-8 bottom-1 border-s-2 border-dotted border-secondary-soft dark:border-secondary-medium"></span>
    @endunless

    <span
        aria-hidden="true"
        @class([
            'relative mt-0.5 grid size-6 place-items-center rounded-full',
            'bg-primary text-white' => $on,
            'border-2 border-secondary-light bg-white dark:border-secondary-light dark:bg-secondary-dark' => ! $on,
        ])
    >
        @if ($on)
            <flux:icon.check variant="micro" class="size-3.5" />
        @endif
    </span>

    <div @class(['min-w-0', 'pb-10' => ! $last])>
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0 flex-1 basis-64">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="type-heading">{{ $title }}</h3>
                    <span
                        data-step-state
                        @class([
                            'type-meta rounded-full px-2 py-0.5',
                            'bg-primary/10 text-primary dark:bg-primary/25 dark:text-white' => $on,
                            'bg-secondary-soft/60 text-secondary-medium dark:bg-white/10 dark:text-secondary-soft' => ! $on,
                        ])
                    >{{ $state }}</span>
                </div>

                @isset($description)
                    <p class="mt-1 text-sm text-secondary-medium dark:text-secondary-soft">{{ $description }}</p>
                @endisset
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>

        @if ($slot->isNotEmpty())
            <div class="mt-4 space-y-4 text-sm">{{ $slot }}</div>
        @endif
    </div>
</li>
