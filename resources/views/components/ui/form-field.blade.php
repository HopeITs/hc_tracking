@props([
    'label',
    'for',
    'required' => false,
    'help' => null,
    'error' => null,
])

{{-- The label, help line and error line every x-ui.form-* field shares, so no two field types can drift apart. --}}
<div {{ $attributes->class('min-w-0') }} data-form-field>
    <label id="{{ $for }}-label" for="{{ $for }}" class="form-label">
        {{ $label }}
        @if ($required)
            <span class="form-required" aria-hidden="true">*</span>
        @endif
    </label>

    {{ $slot }}

    @if ($help !== null && $help !== '')
        <p id="{{ $for }}-help" class="form-help">{{ $help }}</p>
    @endif

    @if ($error !== null && $error !== '')
        <p id="{{ $for }}-error" class="form-error">
            <flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />
            {{ $error }}
        </p>
    @endif
</div>
