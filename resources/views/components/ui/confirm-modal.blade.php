@props([
    'title',
    'message' => null,
    'confirm',
    'confirmLabel',
    'confirmTest' => null,
    'tone' => 'danger',
    'close' => null,
])

@php
    /*
     * A confirmation in the same shell as every form: a small warning mark
     * beside the title, the consequence as its description, then Cancel and
     * the action. `danger` (delete) is red; `neutral` (archive, unlink) is a
     * grey mark and a dark button, because nothing is lost.
     */
    [$markClasses, $buttonTone] = match ($tone) {
        'danger' => ['bg-secondary-soft/60 text-status-critical', 'primary'],
        'neutral' => ['bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink', 'neutral'],
        default => throw new InvalidArgumentException("Unknown x-ui.confirm-modal tone [{$tone}]."),
    };
@endphp

<x-ui.modal size="sm" :sheet="false" :title="$title" :description="$message" :close="$close" :attributes="$attributes">
    <x-slot:mark>
        <span class="confirm-mark {{ $markClasses }}" aria-hidden="true">
            <flux:icon.exclamation-triangle variant="mini" class="size-4" />
        </span>
    </x-slot:mark>

    {{ $slot }}

    <x-slot:actions>
        <x-ui.button type="button" :tone="$buttonTone" wire:click="{{ $confirm }}" :data-test="$confirmTest">{{ $confirmLabel }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
