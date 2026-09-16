@props([
    'id' => null,
    'label',
    'searchable' => null,
    'searchLabel' => null,
    'value' => '',
])

@php
    $model = $attributes->wire('model')->value();

    if ($model === null) {
        throw new InvalidArgumentException('The x-ui.header-filter-select component requires a wire:model binding.');
    }

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
            'disabled' => $option->hasAttribute('disabled'),
            'label' => trim(preg_replace('/\s+/u', ' ', $option->textContent) ?? $option->textContent),
            'value' => $option->getAttribute('value'),
        ];
    }

    $selectedValue = (string) $value;
    $selectedLabel = collect($options)->firstWhere('value', $selectedValue)['label']
        ?? ($options[0]['label'] ?? '');
    $id ??= 'header-filter-'.substr(md5($model.'|'.$label), 0, 12);
    $searchable ??= count($options) > 7;
    $searchLabel ??= $label;
@endphp

<div class="min-w-0" data-header-filter-select>
    <label for="{{ $id }}" class="mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-secondary-soft">
        {{ $label }}
    </label>

    <flux:dropdown position="bottom" align="start" class="program-filter-picker block! w-full!" x-data="{ optionSearch: '' }">
        <flux:button
            :id="$id"
            variant="outline"
            icon:trailing="chevron-up-down"
            class="program-filter-trigger w-full! justify-between!"
            :aria-label="$label"
        >
            <span class="truncate">{{ $selectedLabel }}</span>
        </flux:button>

        <flux:menu class="program-filter-menu">
            @if ($searchable)
                <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
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

            @foreach ($options as $option)
                <flux:menu.item
                    wire:click="$set('{{ $model }}', {{ Illuminate\Support\Js::from($option['value']) }})"
                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                    :disabled="$option['disabled']"
                    :data-selected="$selectedValue === $option['value'] ? 'true' : null"
                    :wire:key="$id.'-'.md5($option['value'])"
                >
                    <span class="min-w-0 truncate">{{ $option['label'] }}</span>

                    @if ($selectedValue === $option['value'])
                        <flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />
                    @endif
                </flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
