{{--
    One outlined field of the proposal card, label in the top border.
    Expects $field (EditsProposalCard::CARD_FIELDS), $display (string|null) and optional $big, $note.
--}}
@php
    $isEditing = $editingField === $field;
    $lock = $isEditing ? null : $this->cardFieldLock($field);
    $hasError = $isEditing && $errors->has('fieldValue');
    $inputType = in_array($field, ['start', 'end', 'sent', 'approved'], true) ? 'date' : 'text';
@endphp

<div class="pp-field-wrap" wire:key="card-field-{{ $field }}">
    @if ($isEditing)
        <div @class(['pp-field', 'pp-field-big' => $big ?? false, 'pp-editing', 'pp-bad' => $hasError])>
            <label class="pp-flabel" for="pp-f-{{ $field }}">{{ __('proposals.page.field_'.$field) }}</label>
            @if ($field === 'donor')
                <select id="pp-f-{{ $field }}" wire:model="fieldValue" wire:change="saveField" x-init="$el.focus()" x-on:keydown.escape.prevent="$wire.cancelField()" data-test="card-input-{{ $field }}">
                    @foreach ($this->donorChoices as $donor)
                        <option value="{{ $donor['id'] }}">{{ $donor['name'] }}</option>
                    @endforeach
                </select>
            @else
                <input id="pp-f-{{ $field }}" type="{{ $inputType }}" wire:model="fieldValue" x-init="$el.focus(); $el.type === 'text' && $el.setSelectionRange($el.value.length, $el.value.length)" x-on:keydown.enter.prevent="$wire.saveField()" x-on:keydown.escape.prevent="$wire.cancelField()" x-on:blur="setTimeout(() => { if (document.activeElement?.closest('.pp-field') === null && $wire.editingField === '{{ $field }}') { $wire.saveField() } }, 120)" @if ($inputType === 'text') maxlength="255" @endif data-test="card-input-{{ $field }}">
            @endif
            <button type="button" class="pp-field-ok" wire:click="saveField" aria-label="{{ __('common.save') }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            </button>
        </div>
        @error('fieldValue')
            <p class="pp-err" role="alert" data-test="card-error-{{ $field }}">{{ $message }}</p>
        @enderror
    @else
        <div
            @class(['pp-field', 'pp-field-big' => $big ?? false, 'pp-locked' => $lock !== null])
            @if ($lock === null)
                role="button" tabindex="0" wire:click="editField('{{ $field }}')" x-on:keydown.enter.prevent="$wire.editField('{{ $field }}')" x-on:keydown.space.prevent="$wire.editField('{{ $field }}')"
                aria-label="{{ __('proposals.page.edit_field', ['field' => __('proposals.page.field_'.$field)]) }}"
            @else
                title="{{ $lock }}"
            @endif
            data-test="card-field-{{ $field }}"
        >
            <span class="pp-flabel">{{ __('proposals.page.field_'.$field) }}</span>
            <span @class(['pp-val', 'pp-unset' => blank($display)]) dir="auto">{{ blank($display) ? __('proposals.page.not_set_yet') : $display }}</span>
            @if (! empty($note))
                <span class="pp-ext-note">{{ $note }}</span>
            @endif
            @if ($lock === null)
                <svg class="pp-pen" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            @endif
        </div>
    @endif
</div>
