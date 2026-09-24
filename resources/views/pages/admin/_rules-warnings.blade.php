{{-- The due-soon warnings: one removable chip per "N working days before", plus a small add field. --}}
<div class="rules-row" data-rule-field>
    <div class="min-w-0">
        <p id="rule-dueSoonDays-label" class="rules-row-label">{{ __('notifications.admin.warn_before') }}</p>
        <p class="rules-row-hint">{{ __('notifications.admin.warn_before_hint') }}</p>
    </div>

    <div class="rules-row-control">
        <div class="flex flex-wrap items-center justify-end gap-1.5" role="group" aria-labelledby="rule-dueSoonDays-label">
            <template x-for="days in warnings" :key="'chip' + days">
                <span class="rules-chip">
                    <span class="tabular-nums" x-text="days"></span>
                    <button
                        type="button"
                        class="rules-chip-remove"
                        x-show="warnings.length > 1"
                        x-on:click="removeWarning(days)"
                        x-bind:aria-label="@js(__('notifications.admin.remove_warning')).replace(':days', days)"
                    >
                        <flux:icon.x-mark variant="micro" />
                    </button>
                </span>
            </template>

            <span class="rules-chip-add" x-show="canAddWarning">
                <input
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="99"
                    x-model="newWarning"
                    x-on:input="warningError = false"
                    x-on:keydown.enter.prevent="addWarning()"
                    placeholder="{{ __('notifications.admin.add_warning_placeholder') }}"
                    aria-label="{{ __('notifications.admin.add_warning') }}"
                />
                <button type="button" x-on:click="addWarning()" aria-label="{{ __('notifications.admin.add_warning') }}">
                    <flux:icon.plus variant="micro" />
                </button>
            </span>
        </div>

        <button type="button" class="rules-default" x-show="isCustom('dueSoonDays')" x-cloak x-on:click="useDefault('dueSoonDays')" x-text="@js(__('notifications.admin.use_default')).replace(':value', defaultLabel('dueSoonDays'))"></button>
    </div>

    <p x-show="warningError" x-cloak class="rules-row-error" role="status">{{ __('notifications.admin.bad_warning') }}</p>
    @error('dueSoonDays') <p class="rules-row-error" data-rule-error>{{ $message }}</p> @enderror
</div>
