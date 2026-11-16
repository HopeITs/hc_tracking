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
    /* Opt-in: ['fields' => [Livewire properties], 'original' => [property => value at open]]. Closing with a different value asks before discarding. */
    'discardGuard' => null,
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
     * width (forms.css). A dialog never steps: every x-ui.form-section shows
     * at once and Save stays in the footer (user decision 2026-11-16, see
     * docs/superpowers/specs/2026-11-16-sectioned-modal-design.md). After a
     * failed save the footer counts the fields that need attention and jumps
     * to the first one.
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

    /*
     * The footer's error button counts live in Alpine, so it gets one template
     * per plural band (Arabic has four: 1, 2, 3-10, 11+) with :count left in.
     */
    $errorLabels = [
        'bands' => collect([1, 2, 3, 11])->mapWithKeys(fn (int $count): array => [$count => trans_choice('common.fields_need_attention', $count, ['count' => ':count'])])->all(),
        'action' => __('common.show_me'),
        'progress' => __('common.required_filled'),
        'section' => [
            'left' => __('common.section_required_left'),
            'done' => __('common.section_done'),
            'toFix' => __('common.section_to_fix'),
        ],
    ];

    /*
     * Five or more top-level form sections earn the section index: a list on
     * the start side that follows the scroll, jumps on click and shows what is
     * done and what failed. The body sits behind the page's @if flag, so a
     * closed dialog counts none and the whole shell re-renders when it opens.
     */
    $sectionCount = preg_match_all('/<section\b[^>]*\bclass="[^"]*\bform-section\b/', $slot->toHtml());
    $indexed = $sectionCount >= 5;

    $modalAttributes = $attributes->class([$maxWidth, 'modal-shell', 'modal-shell-'.$size, 'modal-shell-centered']);

    if ($close !== null) {
        $modalAttributes = $modalAttributes->merge(['wire:close' => $close]);
    }
@endphp

<flux:modal :closable="false" :attributes="$modalAttributes">
    <{{ $frameTag }}
        class="modal-shell-frame"
        @if ($submit !== null) wire:submit="{{ $submit }}" novalidate @endif
        x-data="modalShell(@js($titleId), @js($submitAction), @js($errorLabels), @js($indexed))"
        @if ($discardGuard !== null) data-discard-guard="{{ json_encode($discardGuard) }}" @endif
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

        @if ($indexed)
            <nav class="modal-shell-chips" aria-label="{{ __('common.sections_in_this_form') }}" data-modal-chips>
                <template x-for="(entry, i) in entries" :key="i">
                    <a href="#" class="modal-shell-chip" x-on:click.prevent="jump(i)" x-bind:class="{ 'is-current': i === current, 'is-invalid': entry.errors > 0, 'is-done': entry.done && entry.errors === 0 }" x-bind:aria-current="i === current ? 'true' : null" x-text="entry.label"></a>
                </template>
            </nav>
        @endif

        @if ($slot->hasActualContent())
            @if ($indexed)
                <div class="modal-shell-main">
                    <nav class="modal-shell-index" aria-label="{{ __('common.sections_in_this_form') }}" data-modal-index>
                        <div class="modal-shell-index-progress" x-show="required > 0">
                            <svg class="modal-shell-index-ring" width="44" height="44" viewBox="0 0 44 44" aria-hidden="true">
                                <circle cx="22" cy="22" r="18" />
                                <circle cx="22" cy="22" r="18" stroke-dasharray="113.1" x-bind:stroke-dashoffset="ringOffset()" />
                            </svg>
                            <span x-text="progressLine()"></span>
                        </div>

                        <p class="modal-shell-index-caption" aria-hidden="true">{{ __('common.sections_in_this_form') }}</p>

                        <ol class="modal-shell-index-list">
                            <template x-for="(entry, i) in entries" :key="i">
                                <li>
                                    <a href="#" class="modal-shell-index-entry" x-on:click.prevent="jump(i)" x-bind:class="{ 'is-current': i === current, 'is-invalid': entry.errors > 0, 'is-done': entry.done && entry.errors === 0 }" x-bind:aria-current="i === current ? 'true' : null">
                                        <span class="modal-shell-index-dot" aria-hidden="true" x-text="entry.errors > 0 ? '!' : (entry.done ? '✓' : i + 1)"></span>
                                        <span class="modal-shell-index-label" x-text="entry.label"></span>
                                        <span class="modal-shell-index-optional" x-show="entry.optional && entry.errors === 0" aria-hidden="true">{{ __('common.optional') }}</span>
                                        <span class="modal-shell-index-count" x-show="entry.errors > 0" aria-hidden="true" x-text="entry.errors"></span>
                                        <span class="sr-only" x-show="entry.errors > 0" x-text="errorLine(entry.errors)"></span>
                                    </a>
                                </li>
                            </template>
                        </ol>

                        <p class="modal-shell-index-hint">{{ __('common.ctrl_enter_saves') }}</p>
                    </nav>
            @endif

            <div @class(['modal-shell-body', 'modal-shell-body-flush' => $flush]) x-ref="body">
                {{ $slot }}
            </div>

            @if ($indexed)
                </div>
            @endif
        @endif

        {{-- The server's own count of failed fields, so the footer agrees with any summary the form prints. --}}
        <footer class="modal-shell-footer" data-error-total="{{ isset($errors) ? count($errors->getBag('default')->keys()) : 0 }}">
            @if ($discardGuard !== null)
                <div class="modal-shell-meta flex flex-wrap items-center gap-2" x-show="discardAsked" x-cloak role="alert" data-discard-notice>
                    <span class="font-semibold">{{ __('common.discard_changes_question') }}</span>
                    <button type="button" class="rounded-md px-2 py-1 font-semibold underline underline-offset-2" x-on:click="keepEditing()">{{ __('common.keep_editing') }}</button>
                    <button type="button" class="rounded-md px-2 py-1 font-semibold text-status-critical underline underline-offset-2" x-on:click="discard()" data-discard-confirm>{{ __('common.discard') }}</button>
                </div>
            @endif

            <button type="button" class="modal-shell-error" x-show="errorCount > 0" x-cloak x-on:click="revealFirstError()" data-modal-errors>
                <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                <span x-text="errorLabel()"></span>
            </button>

            @if (isset($footerMeta))
                <div class="modal-shell-meta" x-show="errorCount === 0">{{ $footerMeta }}</div>
            @elseif ($requiredNote)
                <div class="modal-shell-meta modal-shell-meta-note" x-show="errorCount === 0">
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
