@props([
    'id' => null,
    'label',
    'toggle',
    'clear',
    'emptyLabel',
    'searchable' => null,
    'searchLabel' => null,
    'selected' => [],
])

@php
    /**
     * The sibling single-select picker reads its choices from <option> children
     * so every header filter is written the same way at the call site. This one
     * parses them identically rather than inventing a second API.
     *
     * Values stay strings here and are cast by the Livewire action named in
     * `toggle`, so the component never has to know what it is filtering.
     */
    $document = new DOMDocument('1.0', 'UTF-8');
    $previousLibxmlState = libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="utf-8" ?><select>'.(string) $slot.'</select>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $options = [];

    foreach ($document->getElementsByTagName('option') as $option) {
        $options[] = [
            'label' => trim(preg_replace('/\s+/u', ' ', $option->textContent) ?? $option->textContent),
            'value' => $option->getAttribute('value'),
        ];
    }

    $selectedValues = collect($selected)->map(fn ($value): string => (string) $value)->all();

    $selectedLabels = collect($options)
        ->whereIn('value', $selectedValues)
        ->pluck('label')
        ->all();

    $triggerLabel = match (true) {
        $selectedLabels === [] => $emptyLabel,
        count($selectedLabels) <= 2 => implode(', ', $selectedLabels),
        default => __('common.count_selected', ['count' => count($selectedLabels)]),
    };

    $id ??= 'header-filter-'.substr(md5($label), 0, 12);
    $searchable ??= count($options) > 7;
    $searchLabel ??= $label;
@endphp

{{--
    Driven by Alpine rather than flux:dropdown: a flux menu item closes the
    popover as soon as it is chosen, which makes picking a second value cost a
    second trip through the trigger. The Flux data attributes are kept so the
    shared .program-filter-picker styling still applies.
--}}
<div
    class="program-filter-picker min-w-0"
    data-header-filter-select
    x-data="{ open: false, optionSearch: '' }"
    x-on:keydown.escape.stop="open = false"
    x-on:click.outside="open = false"
>
    <label for="{{ $id }}" class="mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-ink-muted">
        {{ $label }}
    </label>

    <div class="relative">
        <button
            type="button"
            id="{{ $id }}"
            data-flux-button
            x-on:click="open = ! open"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="listbox"
            aria-label="{{ $label }}"
            class="program-filter-trigger flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border px-3 text-start text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
            <span class="truncate">{{ $triggerLabel }}</span>
            <flux:icon.chevron-up-down class="size-4 shrink-0 text-secondary-medium dark:text-ink-muted" aria-hidden="true" />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            data-flux-menu
            role="listbox"
            aria-multiselectable="true"
            aria-labelledby="{{ $id }}"
            class="absolute z-30 mt-1 w-full! rounded-lg border p-[.3125rem] shadow-xs"
        >
            @if ($searchable)
                <div class="program-filter-menu border-b border-secondary-soft p-1.5 dark:border-line">
                    <x-ui.input
                        type="search"
                        size="sm"
                        icon="magnifying-glass"
                        x-model="optionSearch"
                        :aria-label="$searchLabel"
                        :placeholder="$searchLabel"
                    />
                </div>
            @endif

            <button
                type="button"
                data-flux-menu-item
                role="option"
                aria-selected="{{ $selectedValues === [] ? 'true' : 'false' }}"
                wire:click="{{ $clear }}"
                class="flex w-full cursor-pointer items-center rounded-md px-2 py-1.5 text-start text-sm font-medium hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
            >
                <span class="min-w-0 truncate">{{ $emptyLabel }}</span>

                @if ($selectedValues === [])
                    <flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />
                @endif
            </button>

            @foreach ($options as $option)
                @php($isSelected = in_array($option['value'], $selectedValues, true))
                <button
                    type="button"
                    data-flux-menu-item
                    role="option"
                    aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                    wire:click="{{ $toggle }}({{ Illuminate\Support\Js::from($option['value']) }})"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    wire:key="{{ $id.'-'.md5($option['value']) }}"
                    class="flex w-full cursor-pointer items-center rounded-md px-2 py-1.5 text-start text-sm font-medium hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                >
                    <span class="min-w-0 truncate">{{ $option['label'] }}</span>

                    @if ($isSelected)
                        <flux:icon.check class="ms-auto size-4 shrink-0 text-primary dark:text-brand-text" />
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
