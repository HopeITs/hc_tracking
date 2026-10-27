@props([
    'size' => 'md',
    'title',
    'description' => null,
    'submit' => null,
    'close' => null,
    'requiredNote' => false,
    'cancelLabel' => null,
    'flush' => false,
    'sheet' => false,
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
     * Every dialog is a centered popup (shell v2: no side sheets; `sheet` is
     * kept only so older call sites still compile). Form dialogs share one
     * width (forms.css). A form with three or more x-ui.form-section blocks
     * becomes a step wizard on its own (resources/js/modal-shell.js): one
     * section per step, a stepper in the header, Back / Next in the footer,
     * the submit actions on the last step, and a fixed height so every wizard
     * on the site takes the same space.
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

    $modalAttributes = $attributes->class([$maxWidth, 'modal-shell', 'modal-shell-'.$size, 'modal-shell-centered']);

    if ($close !== null) {
        $modalAttributes = $modalAttributes->merge(['wire:close' => $close]);
    }
@endphp

<flux:modal :closable="false" :attributes="$modalAttributes">
    <{{ $frameTag }}
        class="modal-shell-frame"
        @if ($submit !== null) wire:submit="{{ $submit }}" novalidate @endif
        x-data="modalShell(@js($titleId), @js($submitAction))"
        x-bind:data-wizard="wizard ? '' : null"
        x-on:keydown.enter="onEnter($event)"
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

        <ol class="modal-shell-steps" x-show="wizard" x-cloak aria-label="{{ __('common.steps') }}" data-modal-steps>
            <template x-for="(label, i) in labels" :key="i">
                <li x-bind:class="{ 'is-current': i + 1 === step, 'is-done': i + 1 < step, 'is-invalid': invalid[i] }" x-bind:aria-current="i + 1 === step ? 'step' : null">
                    <button type="button" x-on:click="go(i + 1)">
                        <span class="modal-shell-step-dot" aria-hidden="true" x-text="i + 1"></span>
                        <span class="modal-shell-step-label" x-text="label"></span>
                    </button>
                </li>
            </template>
        </ol>

        @if ($slot->hasActualContent())
            <div @class(['modal-shell-body', 'modal-shell-body-flush' => $flush]) x-ref="body" x-bind:data-step="wizard ? step : null">
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

                <x-ui.button type="button" x-show="wizard && step > 1" x-cloak x-on:click="back()" data-modal-back>{{ __('common.back') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" x-show="wizard && step < total" x-cloak x-on:click="next()" data-modal-next>{{ __('common.next') }}</x-ui.button>

                <fieldset class="modal-shell-submit" x-bind:disabled="busy" x-show="! wizard || step === total">
                    {{ $actions ?? '' }}
                </fieldset>
            </div>
        </footer>
    </{{ $frameTag }}>
</flux:modal>
