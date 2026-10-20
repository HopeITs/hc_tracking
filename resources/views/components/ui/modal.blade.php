@props([
    'size' => 'md',
    'title',
    'description' => null,
    'submit' => null,
    'close' => null,
    'requiredNote' => false,
    'cancelLabel' => null,
    'flush' => false,
    'sheet' => true,
])

@php
    /*
     * The one shell every create/edit dialog and confirmation uses. It keeps
     * flux:modal underneath (native <dialog>, focus trap, Esc, backdrop, the
     * wire:model boolean) and draws its own header, body and footer: header
     * and footer stay pinned and only the body scrolls, and only when the
     * window is genuinely too short. Flux's floating X is switched off in
     * favour of the header's own.
     *
     * The X, Cancel, Esc and a backdrop click all close the native dialog, so
     * the `close` action runs exactly once, through wire:close, whichever way
     * the user leaves.
     *
     * By default it is a Tracking Desk side sheet (Flux's flyout behaviour with the bare variant, so
     * no physical ml-auto/rtl:mr-auto classes; forms.css docks it to the inline-end edge
     * with logical margins: right in LTR, left in RTL; width by size in forms.css).
     * `:sheet="false"` keeps the small centered dialog that confirmations use.
     */
    $maxWidth = match ($size) {
        'sm' => 'max-w-md',
        'md' => 'max-w-xl',
        'lg' => 'max-w-3xl',
        'xl' => 'max-w-5xl',
        default => throw new InvalidArgumentException("Unknown x-ui.modal size [{$size}]."),
    };

    /* Keyed on the binding, not the title: the title changes between add and edit. */
    $titleId = 'modal-title-'.substr(md5((string) ($attributes->wire('model')->value() ?: $title)), 0, 10);
    $submitAction = $submit === null ? null : Illuminate\Support\Str::before($submit, '(');
    $frameTag = $submit === null ? 'div' : 'form';

    $modalAttributes = $attributes->class([$sheet ? null : $maxWidth, 'modal-shell', 'modal-shell-'.$size, $sheet ? 'modal-shell-sheet' : 'modal-shell-centered']);

    if ($close !== null) {
        $modalAttributes = $modalAttributes->merge(['wire:close' => $close]);
    }
@endphp

<flux:modal :closable="false" :flyout="$sheet" :variant="$sheet ? 'bare' : null" :attributes="$modalAttributes">
    <{{ $frameTag }}
        class="modal-shell-frame"
        @if ($submit !== null) wire:submit="{{ $submit }}" novalidate @endif
        x-data="modalShell(@js($titleId), @js($submitAction))"
    >
        <header class="modal-shell-header">
            @isset($mark)
                {{ $mark }}
            @endisset

            <div class="min-w-0 flex-1">
                <h2 id="{{ $titleId }}" class="modal-shell-title">{{ $title }}</h2>

                @if ($description !== null && $description !== '')
                    <p class="modal-shell-description">{{ $description }}</p>
                @endif
            </div>

            @isset($headerActions)
                <div class="flex shrink-0 items-center gap-1">
                    {{ $headerActions }}
                </div>
            @endisset

            <button type="button" class="modal-shell-close" x-on:click="close()" aria-label="{{ __('common.close') }}">
                <flux:icon.x-mark class="size-5" />
            </button>
        </header>

        @if ($slot->hasActualContent())
            <div @class(['modal-shell-body', 'modal-shell-body-flush' => $flush])>
                {{ $slot }}
            </div>
        @endif

        <footer class="modal-shell-footer">
            @if (isset($footerMeta))
                <div class="modal-shell-meta">{{ $footerMeta }}</div>
            @elseif ($requiredNote)
                <div class="modal-shell-meta modal-shell-meta-note">
                    <span class="form-required" aria-hidden="true">*</span>
                    {{ __('common.required') }}
                </div>
            @endif

            <div class="modal-shell-actions">
                <x-ui.button type="button" x-on:click="close()">{{ $cancelLabel ?? __('common.cancel') }}</x-ui.button>

                <fieldset class="modal-shell-submit" x-bind:disabled="busy">
                    {{ $actions ?? '' }}
                </fieldset>
            </div>
        </footer>
    </{{ $frameTag }}>
</flux:modal>
