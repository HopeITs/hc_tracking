{{--
    Default proposal reviewers (T07, contracts §9): the HR position behind
    each of the four default slots. Its own form and save button, apart from
    the rules form above and its unsaved-changes bar.
--}}
@php
    $positionOptions = $this->positionOptions();
@endphp

<section id="rules-default-reviewers" aria-labelledby="rules-default-reviewers-heading" class="rules-group mt-12 border-t border-secondary-soft pt-10 dark:border-line" data-default-reviewers>
    <h2 id="rules-default-reviewers-heading" class="rules-group-title">{{ __('notifications.admin.default_reviewers.title') }}</h2>
    <p class="rules-group-hint">{{ __('notifications.admin.default_reviewers.hint') }}</p>

    <form wire:submit="saveDefaultReviewers" class="mt-4 grid gap-4" novalidate>
        <div class="grid gap-4 @3xl/rules:grid-cols-2">
            @foreach (\App\Enums\ReviewerSlot::defaultSlots() as $slot)
                <x-ui.form-select wire:model="reviewerPositions.{{ $slot->value }}" :label="$slot->label()" :placeholder="__('notifications.admin.default_reviewers.none')" wire:key="reviewer-slot-{{ $slot->value }}" data-reviewer-slot="{{ $slot->value }}">
                    <option value="">{{ __('notifications.admin.default_reviewers.none') }}</option>
                    @foreach ($positionOptions as $position)
                        <option value="{{ $position['value'] }}">{{ $position['label'] }}</option>
                    @endforeach
                </x-ui.form-select>
            @endforeach
        </div>

        <div>
            <x-ui.button type="submit" tone="primary" icon="check" class="min-h-10 touch-manipulation" wire:loading.attr="disabled" wire:target="saveDefaultReviewers" data-test="save-default-reviewers">{{ __('notifications.admin.default_reviewers.save') }}</x-ui.button>
        </div>
    </form>
</section>
