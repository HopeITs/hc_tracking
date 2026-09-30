@props([
    'label',
    'id' => null,
    'type' => 'text',
    'prefix' => null,
    'suffix' => null,
    'required' => false,
    'help' => null,
])

@php
    /*
     * A text, number, email, phone or date field in the header filter picker's
     * box. Every attribute that is not a prop (wire:model, maxlength, min, step,
     * placeholder, autofocus...) lands on the <input>; only `class` stays on the
     * wrapper, so callers can place the field in their grid. The error message
     * is read from the bag under the wire:model name.
     */
    $model = (string) $attributes->wire('model')->value();
    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $model !== '' ? $errors->first($model) : '';
    $describedBy = collect([
        $prefix !== null ? $id.'-prefix' : null,
        $suffix !== null ? $id.'-suffix' : null,
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')">
    <div class="form-box" @if ($error !== '') data-invalid @endif>
        @if ($prefix !== null)
            <span id="{{ $id }}-prefix" class="form-affix">{{ $prefix }}</span>
        @endif

        <input
            id="{{ $id }}"
            type="{{ $type }}"
            {{ $attributes->except('class')->class(['form-box-input', 'tabular-nums' => $type === 'number']) }}
            @if ($required) required aria-required="true" @endif
            @if ($error !== '') aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        />

        @if ($suffix !== null)
            <span id="{{ $id }}-suffix" class="form-affix">{{ $suffix }}</span>
        @endif
    </div>
</x-ui.form-field>
