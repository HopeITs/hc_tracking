{{--
    One rule: its name and hint, then its control — a − / value / + stepper, or a time field.
    Expects $property, $label; optional $hint, $liveHint (an Alpine expression for a hint that follows the draft),
    $unit (after the number; working days when omitted) and $type ('number' or 'time').
--}}
@php
    $hint ??= null;
    $liveHint ??= null;
    $unit ??= null;
    $type ??= 'number';
    $fieldId = 'rule-'.$property;
@endphp

<div class="rules-row" data-rule-field>
    <div class="min-w-0">
        <label for="{{ $fieldId }}" class="rules-row-label">{{ $label }}</label>
        @if ($liveHint)
            <p class="rules-row-hint" x-text="{{ $liveHint }}"></p>
        @elseif ($hint)
            <p class="rules-row-hint">{{ $hint }}</p>
        @endif
    </div>

    <div class="rules-row-control">
        @if ($type === 'time')
            <input id="{{ $fieldId }}" type="time" wire:model="{{ $property }}" class="rules-time" @error($property) aria-invalid="true" @enderror />
        @else
            <div class="rules-stepper">
                <button type="button" class="rules-stepper-button" x-on:click="step('{{ $property }}', -1)" x-bind:disabled="! canStep('{{ $property }}', -1)" aria-label="{{ __('notifications.admin.decrease', ['label' => $label]) }}">
                    <flux:icon.minus variant="micro" />
                </button>
                <input
                    id="{{ $fieldId }}"
                    type="number"
                    inputmode="numeric"
                    class="rules-stepper-input"
                    value="{{ $this->{$property} }}"
                    x-bind:value="$wire.{{ $property }}"
                    x-bind:min="bounds('{{ $property }}')[0]"
                    x-bind:max="bounds('{{ $property }}')[1]"
                    x-on:input="typeNumber('{{ $property }}', $event)"
                    x-on:change="commitNumber('{{ $property }}', $event)"
                    @error($property) aria-invalid="true" @enderror
                />
                @if ($unit === null)
                    <span class="rules-stepper-unit" aria-hidden="true" x-text="plural('workingDays', num('{{ $property }}'))">{{ trans_choice('notifications.admin.working_days_unit', $this->{$property}) }}</span>
                @else
                    <span class="rules-stepper-unit is-short" aria-hidden="true">{{ $unit }}</span>
                @endif
                <button type="button" class="rules-stepper-button" x-on:click="step('{{ $property }}', 1)" x-bind:disabled="! canStep('{{ $property }}', 1)" aria-label="{{ __('notifications.admin.increase', ['label' => $label]) }}">
                    <flux:icon.plus variant="micro" />
                </button>
            </div>
        @endif

        <button type="button" class="rules-default" x-show="isCustom('{{ $property }}')" x-cloak x-on:click="useDefault('{{ $property }}')" x-text="@js(__('notifications.admin.use_default')).replace(':value', defaultLabel('{{ $property }}'))"></button>
    </div>

    @error($property) <p class="rules-row-error" data-rule-error>{{ $message }}</p> @enderror
</div>
