@props([
    'title',
    'hint' => null,
    'fields' => [],
    'optional' => false,
    'forReview' => false,
])

@php
    /*
     * One section of a form, drawn as a card: a red number (CSS counter), the
     * title with an "Optional" tag when nothing in it is required, a one-line
     * hint, and a status chip the modal shell fills live ("2 required",
     * "Done", "1 to fix"; resources/js/modal-shell.js). A dialog with five or
     * more sections also lists them in its section index (x-ui.modal).
     *
     * An optional section starts folded behind an "Add …" button so the form
     * opens shorter; it unfolds by itself when it already holds text or comes
     * back with an error (formSectionFold).
     *
     * `fields` lists the validation keys this section holds: when any of them
     * fails, the card turns red, the hint becomes the number of fields that
     * need attention, and data-invalid-count carries that number for the
     * index. A `hint` slot replaces the plain-text prop for live figures
     * written in Alpine.
     *
     * `forReview` marks a section the record saves without but cannot go to
     * review without (a proposal's donor and budget): no red star, a
     * "Needed for review" tag here and on its index step instead.
     */
    $invalidCount = collect($fields)->filter(fn (string $field): bool => $errors->has($field))->count();
    $hasHint = $hint !== null && trim((string) $hint) !== '';
@endphp

<section
    {{ $attributes->class('form-section') }}
    data-invalid-count="{{ $invalidCount }}"
    @if ($invalidCount > 0) data-invalid @endif
    @if ($optional) data-optional x-data="formSectionFold" @endif
    @if ($forReview) data-for-review @endif
>
    @if ($optional)
        <button type="button" class="form-section-fold" x-show="! open" x-on:click="unfold()" data-section-fold>
            <span class="form-section-fold-plus" aria-hidden="true">+</span>
            <span class="min-w-0 flex-1">
                <span class="form-section-fold-title">{{ __('common.add_section', ['section' => Illuminate\Support\Str::lower($title)]) }}</span>
                @if ($hasHint)
                    <span class="form-section-fold-hint">{{ $hint }}</span>
                @endif
            </span>
            <span class="form-section-optional">{{ __('common.optional') }}</span>
        </button>
    @endif

    <div class="form-section-card" @if ($optional) x-show="open" x-cloak @endif>
        <div class="form-section-rail">
            <span class="form-section-num" aria-hidden="true"></span>

            <div class="min-w-0 flex-1">
                <h3 class="form-section-heading">
                    <span class="form-section-dot" aria-hidden="true"></span>
                    <span class="form-section-title">{{ $title }}</span>
                    @if ($optional)
                        <span class="form-section-optional">{{ __('common.optional') }}</span>
                    @endif
                    @if ($forReview)
                        <span class="form-section-optional form-section-review" data-for-review-badge>{{ __('common.needed_for_review') }}</span>
                    @endif
                </h3>

                @if ($invalidCount > 0)
                    <p class="form-section-hint">{{ trans_choice('common.fields_need_attention', $invalidCount, ['count' => $invalidCount]) }}</p>
                @elseif ($hasHint)
                    <p class="form-section-hint">{{ $hint }}</p>
                @endif
            </div>

            <span class="form-section-state" wire:ignore data-section-state></span>
        </div>

        <div class="form-section-fields min-w-0">
            {{ $slot }}
        </div>
    </div>
</section>
