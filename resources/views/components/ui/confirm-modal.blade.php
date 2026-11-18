@props([
    'title',
    'message' => null,
    'confirm',
    'confirmLabel',
    'confirmTest' => null,
    'tone' => 'danger',
    'close' => null,
    /* The action's own Heroicon (trash, archive-box, link-slash…); each tone has a fallback. */
    'icon' => null,
    /* The record the action falls on, shown as a card so nobody acts on the wrong one. */
    'record' => null,
    'recordMeta' => null,
    'recordIcon' => 'document-text',
    /* Adds the red "This can't be undone." line under the body. */
    'permanent' => false,
    /* High-stakes deletes: the action stays locked until this exact code is typed. */
    'confirmText' => null,
])

@php
    /*
     * A confirmation in the same shell as every form (x-ui.modal), laid out as
     * its own small dialog (.modal-shell-confirm in forms.css): the action's
     * icon in a tile, the question as the title, the consequence under it, the
     * record it falls on, then Esc / Cancel / the action. No X: Cancel, Esc and
     * the backdrop already close it, and the browser's first focus lands on
     * Cancel (or the code field), so a stray Enter never deletes.
     *
     * `danger` (delete) is a red tile and a red button; `neutral` (archive,
     * unlink, approve) a dark tile and a dark button, because nothing is lost.
     * Below 40rem the dialog becomes a bottom sheet with full-width buttons.
     */
    [$markTone, $buttonTone, $fallbackIcon] = match ($tone) {
        'danger' => ['danger', 'primary', 'trash'],
        'neutral' => ['neutral', 'neutral', 'exclamation-circle'],
        default => throw new InvalidArgumentException("Unknown x-ui.confirm-modal tone [{$tone}]."),
    };

    $markIcon = $icon ?? $fallbackIcon;
    $typedCode = filled($confirmText) ? (string) $confirmText : null;
    $typeLabel = $typedCode === null ? null : str_replace(':code', '<code class="confirm-code">'.e($typedCode).'</code>', e(__('common.type_to_confirm')));
@endphp

<x-ui.modal size="sm" :sheet="false" :title="$title" :description="$message" :close="$close" :attributes="$attributes->class('modal-shell-confirm')">
    <x-slot:mark>
        <span class="confirm-mark confirm-mark-{{ $markTone }}" aria-hidden="true" data-confirm-mark>
            <flux:icon :name="$markIcon" class="size-[1.375rem]" />
        </span>
    </x-slot:mark>

    @if (filled($record))
        <div class="confirm-record" data-confirm-record>
            <span class="confirm-record-icon" aria-hidden="true">
                <flux:icon :name="$recordIcon" class="size-[1.125rem]" />
            </span>
            <span class="min-w-0">
                <span class="confirm-record-name">{{ $record }}</span>
                @if (filled($recordMeta))
                    <span class="confirm-record-meta">{{ $recordMeta }}</span>
                @endif
            </span>
        </div>
    @endif

    {{ $slot }}

    @if ($typedCode !== null)
        <div class="confirm-type" data-confirm-type>
            <label for="{{ $attributes->wire('model')->value() }}-confirm-text" class="confirm-type-label">{!! $typeLabel !!}</label>
            <input
                type="text"
                id="{{ $attributes->wire('model')->value() }}-confirm-text"
                class="confirm-type-input"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                dir="ltr"
                placeholder="{{ $typedCode }}"
                x-on:input="const action = $el.closest('.modal-shell-frame').querySelector('[data-confirm-action]'); const matches = $el.value.trim().toUpperCase() === @js(mb_strtoupper($typedCode)); action.disabled = ! matches; $el.closest('[data-confirm-type]').toggleAttribute('data-matches', matches)"
                x-on:keydown.enter.prevent="const action = $el.closest('.modal-shell-frame').querySelector('[data-confirm-action]'); if (! action.disabled) { action.click() }"
                data-confirm-text
            />
        </div>
    @endif

    @if ($permanent)
        <p class="confirm-note" data-confirm-permanent>
            <flux:icon.exclamation-triangle variant="mini" class="size-4 shrink-0" aria-hidden="true" />
            <span>{{ __('common.cannot_be_undone') }}</span>
        </p>
    @endif

    <x-slot:footer-meta>
        <span class="confirm-hint" aria-hidden="true"><kbd>{{ __('common.key_esc') }}</kbd> {{ __('common.to_cancel') }}</span>
    </x-slot:footer-meta>

    <x-slot:actions>
        <x-ui.button type="button" :tone="$buttonTone" wire:click="{{ $confirm }}" :data-test="$confirmTest" data-confirm-action :disabled="$typedCode !== null">{{ $confirmLabel }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
