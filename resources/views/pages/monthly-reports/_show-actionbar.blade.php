{{-- The sticky bar of ⚡show.blade.php: save state and the one main action of this viewer (Submit or Validate) with why it is blocked. --}}
@php
    $submitBlocker = $this->submitBlocker;
    $validateBlocked = $this->pendingMealLines > 0;
    $saveTargets = 'figures,activityEntries,answers,saveFile,deleteFile';
@endphp

@if ($abilities['submit'] || $abilities['validate'])
    <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 border-t border-secondary-soft bg-white/95 py-3 backdrop-blur dark:border-line dark:bg-surface-0/95">
        {{-- The save state only means something to someone who can change the page: the preparer, or a validator with MEAL lines to fill. A validator reading a locked report saw "Saved" for work they never did. --}}
        @if ($abilities['submit'] || $isEditable)
            <p class="flex items-center gap-1.5 text-sm text-secondary-medium dark:text-ink-muted" aria-live="polite" data-save-state>
                <span wire:loading wire:target="{{ $saveTargets }}">{{ __('monthly_reports.saving') }}</span>
                <span wire:dirty wire:target="figures,activityEntries,answers">
                    <span wire:loading.remove wire:target="{{ $saveTargets }}">{{ __('monthly_reports.unsaved_changes') }}</span>
                </span>
                <span wire:dirty.remove wire:target="figures,activityEntries,answers">
                    <span wire:loading.remove wire:target="{{ $saveTargets }}">
                        {{ $errors->any() ? __('monthly_reports.save_needs_attention') : __('monthly_reports.saved') }}
                    </span>
                </span>
            </p>
        @else
            <span aria-hidden="true"></span>
        @endif

        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($abilities['submit'])
                @if ($submitBlocker !== null)
                    <p class="flex items-center gap-1.5 text-sm text-secondary-medium dark:text-ink-muted" data-submit-blocked>
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ $submitBlocker }}
                    </p>
                @endif
                <span data-monthly-report-submit>
                    <x-ui.button
                        tone="primary"
                        icon="paper-airplane"
                        class="rtl:[&_svg]:-scale-x-100"
                        wire:click="openSubmitModal"
                        wire:loading.attr="disabled"
                        wire:target="{{ $saveTargets }},openSubmitModal"
                        :disabled="$submitBlocker !== null"
                        data-test="submit-button"
                    >{{ __('monthly_reports.submit') }}</x-ui.button>
                </span>
            @endif

            @if ($abilities['validate'])
                @if ($validateBlocked)
                    <p class="flex items-center gap-1.5 text-sm text-secondary-medium dark:text-ink-muted" data-validate-blocked>
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ __('monthly_reports.meal_lines_pending') }}
                    </p>
                @endif
                <span data-monthly-report-validate>
                    <x-ui.button
                        tone="primary"
                        icon="check-badge"
                        wire:click="openValidateModal"
                        wire:loading.attr="disabled"
                        wire:target="{{ $saveTargets }},openValidateModal"
                        :disabled="$validateBlocked"
                        data-test="validate-button"
                    >{{ __('monthly_reports.validate') }}</x-ui.button>
                </span>
            @endif
        </div>
    </div>
@endif
