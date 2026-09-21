@props(['title' => null, 'subtitle' => null])

{{-- A right-side slide-over. Bind visibility with wire:model on this component, e.g. <x-ui.drawer wire:model="showTaskDrawer">. --}}
<div
    x-data="{ open: false }"
    x-modelable="open"
    {{ $attributes->whereStartsWith('wire:model') }}
    x-show="open"
    x-cloak
    x-on:keydown.escape.window="open = false"
    class="fixed inset-0 z-50"
    style="display: none"
>
    <div
        class="fixed inset-0 bg-secondary-dark/40"
        x-show="open"
        x-transition.opacity
        x-on:click="open = false"
        aria-hidden="true"
    ></div>

    <div
        class="fixed inset-y-0 end-0 flex w-full max-w-xl flex-col border-s border-secondary-soft bg-white shadow-2xl dark:border-secondary-medium dark:bg-secondary-dark"
        x-show="open"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-x-full rtl:-translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full rtl:-translate-x-full"
        role="dialog"
        aria-modal="true"
        @if ($title !== null) aria-label="{{ $title }}" @endif
    >
        @if ($title !== null)
            <div class="flex items-start justify-between gap-3 border-b border-secondary-soft p-4 dark:border-secondary-medium">
                <div class="min-w-0">
                    <flux:heading size="lg">{{ $title }}</flux:heading>
                    @if ($subtitle !== null)
                        <flux:subheading>{{ $subtitle }}</flux:subheading>
                    @endif
                </div>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white"
                    aria-label="{{ __('common.cancel') }}"
                >
                    <flux:icon.x-mark class="size-5" />
                </button>
            </div>
        @endif

        <div class="flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="border-t border-secondary-soft p-4 dark:border-secondary-medium">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
