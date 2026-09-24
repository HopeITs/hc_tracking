@props([
    'title',
    'hint' => null,
    'fields' => [],
])

@php
    /*
     * One section of a long form. In a wide dialog its name sits in a rail
     * beside the fields, one dot per section on a single thread; narrower
     * dialogs fold it into a heading above them (see .form-section in app.css).
     *
     * `fields` lists the validation keys this section holds: when any of them
     * fails, the dot turns red and the hint becomes the number of fields that
     * need attention. A `hint` slot replaces the plain-text prop for live
     * figures written in Alpine.
     */
    $invalidCount = collect($fields)->filter(fn (string $field): bool => $errors->has($field))->count();
@endphp

<section {{ $attributes->class('form-section') }} @if ($invalidCount > 0) data-invalid @endif>
    <div class="form-section-rail">
        <h3 class="form-section-heading">
            <span class="form-section-dot" aria-hidden="true"></span>
            {{ $title }}
        </h3>

        @if ($invalidCount > 0)
            <p class="form-section-hint">{{ trans_choice('common.fields_need_attention', $invalidCount, ['count' => $invalidCount]) }}</p>
        @elseif ($hint !== null && trim((string) $hint) !== '')
            <p class="form-section-hint">{{ $hint }}</p>
        @endif
    </div>

    <div class="form-section-fields min-w-0">
        {{ $slot }}
    </div>
</section>
