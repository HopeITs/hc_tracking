<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use Livewire\Component;

/**
 * My notes: a private scratch pad saved on users.desk_notes as the viewer
 * types (debounced) and when they leave the box. Only its owner reads it;
 * at most MaxLength characters, checked on the server.
 */
new class extends Component {
    use IsDeskPanel;

    public const int MaxLength = 5000;

    public string $notes = '';

    public function mount(): void
    {
        $this->notes = (string) auth()->user()->desk_notes;
    }

    public function updatedNotes(): void
    {
        $this->validate(
            ['notes' => ['nullable', 'string', 'max:'.self::MaxLength]],
            ['notes.max' => __('desk-cards.notes.too_long', ['max' => self::MaxLength])],
        );

        $user = auth()->user();
        $user->forceFill(['desk_notes' => trim($this->notes) === '' ? null : $this->notes])->save();
    }
};
?>

@php
    $length = mb_strlen($notes);
@endphp

<x-desk.panel id="notes" class="dk-notes-panel" :filterable="false" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total">
    <div class="dk-notes">
        <label for="dk-notes-{{ $index }}" class="sr-only">{{ __('desk-cards.notes.label') }}</label>
        <textarea
            id="dk-notes-{{ $index }}"
            class="dk-notes-box"
            wire:model.live.debounce.800ms="notes"
            x-on:blur="$wire.$commit()"
            maxlength="{{ $this::MaxLength }}"
            placeholder="{{ __('desk-cards.notes.placeholder') }}"
            @error('notes') aria-invalid="true" aria-describedby="dk-notes-err-{{ $index }}" @enderror
        ></textarea>
        <p class="dk-notes-foot" aria-live="polite">
            @error('notes')
                <span id="dk-notes-err-{{ $index }}" class="dk-bad">{{ $message }}</span>
            @else
                <span class="dk-notes-state" wire:dirty.class="dk-saving" wire:target="notes">
                    <i aria-hidden="true"></i>
                    <span wire:dirty.remove wire:target="notes">{{ __('desk-cards.notes.saved') }}</span>
                    <span wire:dirty wire:target="notes">{{ __('desk-cards.notes.saving') }}</span>
                    · {{ __('desk-cards.notes.private') }}
                </span>
            @enderror
            <span @class(['dk-notes-count dk-num', 'dk-bad' => $length > $this::MaxLength * 0.95])>{{ __('desk-cards.notes.count', ['count' => number_format($length), 'max' => number_format($this::MaxLength)]) }}</span>
        </p>
    </div>
</x-desk.panel>
