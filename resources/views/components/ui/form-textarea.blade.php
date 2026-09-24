@props([
    'label',
    'id' => null,
    'required' => false,
    'help' => null,
])

@php
    /* Same box and states as x-ui.form-input; no resize handle, three rows unless the caller says otherwise. */
    $model = (string) $attributes->wire('model')->value();
    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $model !== '' ? $errors->first($model) : '';
    $describedBy = collect([
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')">
    <div class="form-box form-box-multiline" @if ($error !== '') data-invalid @endif>
        <textarea
            id="{{ $id }}"
            {{ $attributes->except('class')->merge(['rows' => 3])->class('form-box-input') }}
            @if ($required) required aria-required="true" @endif
            @if ($error !== '') aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        ></textarea>
    </div>
</x-ui.form-field>
