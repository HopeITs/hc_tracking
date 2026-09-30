{{-- Working week: seven day tiles, each a real checkbox bound to workingDays. --}}
@php
    $weekdayNames = $this->weekdayNames('l');
    $weekdayShort = $this->weekdayNames('D');
@endphp

<section id="rules-week" aria-labelledby="rules-week-heading" class="rules-group" data-rule-field>
    <div class="flex items-baseline justify-between gap-4">
        <h2 id="rules-week-heading" class="rules-group-title">{{ __('notifications.admin.sections.week') }}</h2>
        <p class="text-sm font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" aria-live="polite" x-text="workingCountLabel">{{ count($workingDays) }} {{ trans_choice('notifications.admin.working_days_unit', count($workingDays)) }}</p>
    </div>
    <p class="rules-group-hint">{{ __('notifications.admin.week_hint') }}</p>

    <fieldset class="mt-4">
        <legend class="sr-only">{{ __('notifications.admin.working_days') }}</legend>
        <div class="rules-week" x-bind:class="weekShake && 'is-shaking'" x-on:animationend.self="weekShake = false">
            @foreach (range(1, 7) as $weekday)
                <label class="rules-day" data-weekday="{{ $weekday }}" x-on:click="guardLastDay($event, {{ $weekday }})">
                    <input type="checkbox" wire:model="workingDays" value="{{ $weekday }}" class="sr-only" />
                    <span class="rules-day-name">
                        <span class="rules-day-full">{{ $weekdayNames[$weekday - 1] }}</span>
                        <span class="rules-day-short" aria-hidden="true">{{ $weekdayShort[$weekday - 1] }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p x-show="weekNotice" x-cloak class="rules-row-error" role="status">{{ __('notifications.admin.keep_one_day') }}</p>
        @error('workingDays') <p class="rules-row-error" data-rule-error>{{ $message }}</p> @enderror
    </fieldset>

    <button type="button" class="rules-default mt-2" x-show="isCustom('workingDays')" x-cloak x-on:click="useDefault('workingDays')">{{ __('notifications.admin.use_default_week') }}</button>
</section>
