@props([
    'label',
    'id' => null,
    'placeholder' => null,
    'required' => false,
    'help' => null,
    'disabled' => false,
    'lockedReason' => null,
    'searchable' => null,
    'searchLabel' => null,
    'value' => null,
    'multiple' => false,
])

@php
    /*
     * A form dropdown that is the header filter picker by construction: the
     * same flux:dropdown / flux:menu markup inside the same .filter-picker
     * hook, so app.css styles both from one set of rules. Choices come from
     * <option> children, the convention x-ui.header-filter-select already
     * uses, so a flux:select migrates by renaming its tag.
     *
     * The bound value lives in Alpine (formPicker, resources/js/form-fields.js)
     * and reaches Livewire through x-modelable, so wire:model stays deferred
     * unless the caller writes wire:model.live. The server renders the same
     * label and check mark first, reading the current value from the Livewire
     * component unless `value` is passed.
     */
    /* Livewire's wire() macro answers false, not null, when there is no binding. */
    $model = (string) $attributes->wire('model')->value();

    if ($model === '') {
        throw new InvalidArgumentException('The x-ui.form-select component requires a wire:model binding.');
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

    $livewire = $__env->shared('__livewire');
    $rawValue = $value ?? ($livewire !== null ? data_get($livewire, $model) : null);
    $toString = fn (mixed $item): string => $item instanceof BackedEnum ? (string) $item->value : (string) ($item ?? '');
    $currentValues = $multiple
        ? collect(is_iterable($rawValue) ? $rawValue : [])->map($toString)->values()->all()
        : [$toString($rawValue)];
    $currentValue = $currentValues[0] ?? '';
    $selectedLabels = collect($options)->whereIn('value', $currentValues)->pluck('label')->all();
    $selectedLabel = match (true) {
        $selectedLabels === [] => null,
        ! $multiple, count($selectedLabels) <= 2 => implode(', ', $selectedLabels),
        default => __('common.count_selected', ['count' => count($selectedLabels)]),
    };

    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $searchable ??= count($options) > 7;
    $searchLabel ??= $label;
    $locked = $disabled && $lockedReason !== null;
    $helpText = $locked ? $lockedReason : $help;
    $error = $errors->first($model) ?: ($multiple ? $errors->first($model.'.*') : '');
    $describedBy = collect([
        $helpText !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');

    /* flux:menu.item's own default-variant classes (vendor/livewire/flux/stubs/resources/views/flux/menu/item.blade.php). */
    $menuItemClasses = 'flex items-center px-2 py-1.5 w-full focus:outline-hidden select-none rounded-md text-start text-sm font-medium [&[disabled]]:opacity-50 text-zinc-800 data-active:bg-zinc-50 dark:text-white dark:data-active:bg-zinc-600 **:data-flux-menu-item-icon:text-zinc-400 dark:**:data-flux-menu-item-icon:text-white/60 [&[data-active]_[data-flux-menu-item-icon]]:text-current';
    $checkClasses = 'shrink-0 [:where(&)]:size-6 ms-auto size-4 shrink-0 '.($multiple ? 'text-primary dark:text-brand-text' : 'text-secondary-medium');
@endphp

<x-ui.form-field
    :label="$label"
    :for="$id"
    :required="$required"
    :help="$helpText"
    :error="$error"
    :class="$attributes->get('class')"
    data-form-select="{{ $model }}"
>
    <div
        class="min-w-0"
        x-data="formPicker(@js([
            'value' => $multiple ? $currentValues : $currentValue,
            'multiple' => $multiple,
            'placeholder' => (string) $placeholder,
            'countTemplate' => __('common.count_selected', ['count' => '__COUNT__']),
        ]))"
        x-modelable="value"
        {{ $attributes->whereStartsWith('wire:model') }}
        x-on:keydown.capture="onKeydown($event)"
    >
        <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
            <flux:button
                :id="$id"
                variant="outline"
                icon:trailing="chevron-up-down"
                class="w-full! justify-between!"
                :disabled="$disabled"
                :autofocus="$attributes->has('autofocus')"
                :aria-invalid="$error !== '' ? 'true' : null"
                :aria-describedby="$describedBy !== '' ? $describedBy : null"
                :data-test="$attributes->get('data-test')"
                x-ref="trigger"
                x-on:click="prepareMenu()"
                x-on:keydown.down="prepareMenu()"
            >
                <span class="flex min-w-0 items-center gap-1.5">
                    @if ($locked)
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                    @endif

                    <span
                        class="{{ $selectedLabel === null ? 'form-select-placeholder ' : '' }}truncate"
                        data-trigger-label
                        x-bind:class="{ 'form-select-placeholder': triggerLabel === null }"
                        x-text="triggerLabel ?? placeholder"
                    >{{ $selectedLabel ?? $placeholder }}</span>
                </span>
            </flux:button>

            <flux:menu class="filter-menu" :keep-open="$multiple" x-ref="menu" x-on:focus="focusSearch()">
                @if ($searchable)
                    <div class="border-b border-secondary-soft p-1.5 dark:border-line">
                        <x-ui.input
                            type="search"
                            size="sm"
                            icon="magnifying-glass"
                            x-ref="search"
                            x-model="search"
                            :aria-label="$searchLabel"
                            :placeholder="$searchLabel"
                        />
                    </div>
                @endif

                {{-- The markup flux:menu.item and flux:icon.check render, written
                     out: a person field lists 270+ employees, and two component
                     renders each cost ~195 ms per form. --}}
                @foreach ($options as $option)
                    <button
                        type="button"
                        class="{{ $menuItemClasses }}"
                        data-flux-menu-item
                        data-option-value="{{ $option['value'] }}"
                        data-option-label="{{ $option['label'] }}"
                        @disabled($option['disabled'])
                        x-show="matches($el)"
                        x-bind:disabled="{{ $option['disabled'] ? 'true' : '! matches($el)' }}"
                        x-on:click="choose({{ Illuminate\Support\Js::from($option['value']) }})"
                        wire:key="{{ $id.'-'.md5($option['value']) }}"
                    >
                        <div class="w-7 hidden [[data-flux-menu]:has(>[data-flux-menu-item-has-icon])_&]:block"></div>
                        <span class="min-w-0 truncate">{{ $option['label'] }}</span>

                        <svg class="{{ $checkClasses }}" @unless (in_array($option['value'], $currentValues, true)) style="display: none" @endunless x-show="isSelected({{ Illuminate\Support\Js::from($option['value']) }})" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </button>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>
</x-ui.form-field>
