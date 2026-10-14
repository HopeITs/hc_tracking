@props([
    'sectors',
    'empty' => null,
])

{{--
    A project's sectors as chips (T34), in the managers' order, used on the
    project page and the project list. An inactive sector keeps its chip with
    an "Inactive" word, never a colour alone. Pass the eager-loaded
    `sectors` relation; this component runs no query.
--}}
@if ($sectors->isNotEmpty())
    <ul {{ $attributes->class('flex flex-wrap items-center gap-1') }} aria-label="{{ __('sectors.sectors') }}">
        @foreach ($sectors as $sector)
            <li class="inline-flex items-center gap-1 rounded-full bg-secondary-soft px-2 py-0.5 text-[11px] font-medium leading-4 text-secondary-dark dark:bg-surface-2 dark:text-ink" wire:key="sector-chip-{{ $sector->id }}">
                <span dir="auto">{{ $sector->localizedName() }}</span>
                @unless ($sector->is_active)
                    <span class="inline-flex items-center gap-0.5 rounded-full border border-secondary-light px-1 text-[10px] font-semibold text-secondary-medium dark:border-line-strong dark:text-ink-muted">
                        <flux:icon.pause-circle variant="micro" class="size-2.5" aria-hidden="true" />{{ __('sectors.inactive') }}
                    </span>
                @endunless
            </li>
        @endforeach
    </ul>
@elseif ($empty !== null)
    <span {{ $attributes->class('text-secondary-medium dark:text-ink-muted') }}>{{ $empty }}</span>
@endif
