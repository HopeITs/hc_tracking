{{--
    The attention chips and view toggle of indicators/index. Included by
    ⚡index.blade.php, whose top PHP block defines $focusChips and
    $filtersAreActive.
--}}
    {{-- Attention chips on one side, the way of reading the page on the other. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($focusChips as $chip)
            <button
                type="button"
                wire:click="setFocus('{{ $chip['key'] }}')"
                wire:key="focus-chip-{{ $chip['key'] }}"
                aria-pressed="{{ $focus === $chip['key'] ? 'true' : 'false' }}"
                data-test="focus-chip-{{ $chip['key'] }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' => $focus === $chip['key'],
                    'border-secondary-soft bg-white text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-line dark:bg-surface-1 dark:text-ink-muted dark:hover:text-ink' => $focus !== $chip['key'],
                ])
            >
                <flux:icon :name="$chip['icon']" variant="micro" class="size-3.5" aria-hidden="true" />
                {{ $chip['label'] }}
                <span @class([
                    'inline-flex min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-bold tabular-nums',
                    'bg-white/20' => $focus === $chip['key'],
                    'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => $focus !== $chip['key'],
                ])>{{ $chip['count'] }}</span>
            </button>
        @endforeach

        @if ($filtersAreActive)
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        @endif

        <x-ui.view-toggle
            class="ms-auto"
            wire-model="view"
            :active="$view"
            :label="__('indicators.how_to_read_the_indicators')"
            :options="[
                ['key' => 'cards', 'label' => __('indicators.cards'), 'icon' => 'squares-2x2'],
                ['key' => 'table', 'label' => __('indicators.table'), 'icon' => 'table-cells'],
            ]"
        />
    </div>
