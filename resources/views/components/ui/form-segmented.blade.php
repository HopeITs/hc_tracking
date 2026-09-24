@props([
    'label',
    'options',
    'id' => null,
    'required' => false,
    'help' => null,
    'value' => null,
    'disabled' => false,
])

@php
    /*
     * Two to four fixed choices as one-click segments in the same 44px box as
     * every other field. A radiogroup with a roving tab stop: Tab enters on
     * the chosen segment (or the first), arrows move and choose. The value
     * binds through x-modelable like x-ui.form-select.
     *
     * Each option is ['value', 'label'] plus an optional 'dot' (classes for a
     * small status dot) or 'icon' + 'iconClass' (a Flux icon name), and an
     * optional 'test' for a data-test hook on that segment.
     */
    /* Livewire's wire() macro answers false, not null, when there is no binding. */
    $model = (string) $attributes->wire('model')->value();

    if ($model === '') {
        throw new InvalidArgumentException('The x-ui.form-segmented component requires a wire:model binding.');
    }

    $livewire = $__env->shared('__livewire');
    $rawValue = $value ?? ($livewire !== null ? data_get($livewire, $model) : null);
    /* A boolean property reads as '1' / '0', the values its options carry; PHP casts them back on save. */
    $currentValue = match (true) {
        is_bool($rawValue) => $rawValue ? '1' : '0',
        $rawValue instanceof BackedEnum => (string) $rawValue->value,
        default => (string) ($rawValue ?? ''),
    };
    $values = array_map(fn (array $option): string => (string) $option['value'], $options);
    $tabStop = in_array($currentValue, $values, true) ? $currentValue : ($values[0] ?? '');

    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $errors->first($model);
    $describedBy = collect([
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')">
    <div
        id="{{ $id }}"
        role="radiogroup"
        aria-labelledby="{{ $id }}-label"
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($error !== '') aria-invalid="true" data-invalid @endif
        class="form-segmented"
        x-data="formSegmented(@js($currentValue), @js($values))"
        x-modelable="value"
        {{ $attributes->whereStartsWith('wire:model') }}
        x-on:keydown.left.prevent="step($event, -1)"
        x-on:keydown.right.prevent="step($event, 1)"
        x-on:keydown.up.prevent="step($event, -1)"
        x-on:keydown.down.prevent="step($event, 1)"
    >
        @foreach ($options as $option)
            @php
                $optionValue = (string) $option['value'];
            @endphp

            <button
                type="button"
                role="radio"
                class="form-segment"
                data-option-value="{{ $optionValue }}"
                aria-checked="{{ $currentValue === $optionValue ? 'true' : 'false' }}"
                tabindex="{{ $tabStop === $optionValue ? '0' : '-1' }}"
                x-bind:aria-checked="isChecked(@js($optionValue)) ? 'true' : 'false'"
                x-bind:tabindex="tabStop() === @js($optionValue) ? 0 : -1"
                x-on:click="value = @js($optionValue)"
                wire:key="{{ $id }}-{{ md5($optionValue) }}"
                @if (($option['test'] ?? null) !== null) data-test="{{ $option['test'] }}" @endif
                @disabled($disabled)
            >
                @if (($option['dot'] ?? null) !== null)
                    <span class="size-2 shrink-0 rounded-full {{ $option['dot'] }}" aria-hidden="true"></span>
                @elseif (($option['icon'] ?? null) !== null)
                    <flux:icon :icon="$option['icon']" variant="micro" class="size-3.5 shrink-0 {{ $option['iconClass'] ?? '' }}" />
                @endif

                <span class="truncate">{{ $option['label'] }}</span>
            </button>
        @endforeach
    </div>
</x-ui.form-field>
