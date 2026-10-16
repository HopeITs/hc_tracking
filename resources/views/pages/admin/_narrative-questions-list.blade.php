{{--
    The narrative question bank of admin/narrative-questions, one ordered list
    per frequency (both unless the filter picks one). Included by
    ⚡narrative-questions.blade.php.

    Each row reads as one block: the position within its frequency (an LTR
    number), the question in the reader's language with the other language
    under it, then the frequency and, when off, an "Inactive" badge (words,
    never a colour alone). Up/down are vertical, so they need no flipping in
    RTL; they move within the frequency, each button names its question, and
    the page's live region announces the new position.
--}}
@use('Illuminate\Support\Str')

@php
    $readsArabic = app()->getLocale() === 'ar';
    $badgeClasses = 'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold leading-4';
@endphp

{{--
    After a move, focus returns to the moved question's arrow in the same
    direction, or to the other arrow when that one is now disabled (the row
    reached an end), so a keyboard user can keep pressing Enter. Flux re-enables
    the clicked button only after the request, hence the deferred focus.
--}}
<div
    class="grid gap-6"
    data-narrative-questions-list
    x-data="{
        refocus(questionId, direction) {
            const row = this.$el.querySelector(`[data-narrative-question='${questionId}']`);
            if (! row) {
                return;
            }
            const wanted = row.querySelector(`[data-test='move-narrative-question-${direction}']`);
            const other = row.querySelector(`[data-test='move-narrative-question-${direction === 'up' ? 'down' : 'up'}']`);
            (wanted && ! wanted.disabled ? wanted : other)?.focus();
        },
    }"
    x-on:narrative-question-moved.window="$nextTick(() => setTimeout(() => refocus($event.detail.questionId, $event.detail.direction), 50))"
>
    @foreach ($groups as $frequencyValue => $group)
        @continue($group['questions']->isEmpty())

        @php
            $groupFrequency = $group['frequency'];
            $groupQuestions = $group['questions'];
            $total = $groupQuestions->count();
            $groupHeadingId = 'narrative-questions-group-'.$frequencyValue;
        @endphp

        <section aria-labelledby="{{ $groupHeadingId }}" wire:key="narrative-questions-group-{{ $frequencyValue }}" data-frequency-group="{{ $frequencyValue }}">
            <div class="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="{{ $groupHeadingId }}" class="text-base font-semibold">{{ $groupFrequency->label() }}</h2>
                <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $total }}</span>
                <p class="type-meta basis-full text-secondary-medium dark:text-ink-muted">{{ __('narrative_questions.frequency_help.'.$frequencyValue) }}</p>
            </div>

            <ol
                class="divide-y divide-secondary-soft rounded-xl border border-secondary-soft dark:divide-line dark:border-transparent dark:bg-surface-1 dark:shadow-raised"
                aria-label="{{ __('narrative_questions.list_label', ['frequency' => $groupFrequency->label()]) }}"
            >
                @foreach ($groupQuestions as $question)
                    @php
                        $displayText = $question->localizedText();
                        $shortText = Str::limit($displayText, 80);
                        $secondaryText = match (true) {
                            blank($question->text_en) => null,
                            $readsArabic => $question->text_en,
                            default => $question->text_ar,
                        };
                    @endphp
                    <li wire:key="narrative-question-{{ $question->id }}" data-narrative-question="{{ $question->id }}" @class(['flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $question->is_active])>
                        <span class="mt-0.5 w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('narrative_questions.position', ['position' => $loop->iteration]) }}">{{ $loop->iteration }}</span>

                        <div class="min-w-48 flex-1 basis-60">
                            <p class="text-[15px] font-semibold leading-snug break-words" dir="auto">{{ $displayText }}</p>
                            @if ($secondaryText !== null)
                                <p class="mt-0.5 text-sm break-words text-secondary-medium dark:text-ink-muted" dir="auto">{{ $secondaryText }}</p>
                            @endif
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="{{ $badgeClasses }} border-secondary-light text-secondary-medium dark:border-line-strong dark:text-ink-muted" data-test="narrative-question-frequency">
                                    <flux:icon.calendar-days variant="micro" class="size-3" aria-hidden="true" />{{ $question->frequency->label() }}
                                </span>
                                @if (! $question->is_active)
                                    <span class="{{ $badgeClasses }} border-secondary-light text-secondary-dark dark:border-line-strong dark:text-ink" data-test="narrative-question-inactive-badge">
                                        <flux:icon.pause-circle variant="micro" class="size-3" aria-hidden="true" />{{ __('narrative_questions.inactive') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                            <x-ui.button
                                tone="ghost" size="sm" icon="arrow-up" class="size-9!"
                                wire:click="moveQuestion({{ $question->id }}, 'up')"
                                wire:target="moveQuestion"
                                wire:loading.attr="disabled"
                                :disabled="$loop->first"
                                :aria-label="__('narrative_questions.move_up_name', ['text' => $shortText])"
                                data-test="move-narrative-question-up"
                            />
                            <x-ui.button
                                tone="ghost" size="sm" icon="arrow-down" class="size-9!"
                                wire:click="moveQuestion({{ $question->id }}, 'down')"
                                wire:target="moveQuestion"
                                wire:loading.attr="disabled"
                                :disabled="$loop->iteration === $total"
                                :aria-label="__('narrative_questions.move_down_name', ['text' => $shortText])"
                                data-test="move-narrative-question-down"
                            />
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $question->id }})" wire:target="openEditModal({{ $question->id }})" :aria-label="__('common.edit_name', ['name' => $shortText])" />
                            @if ($question->is_active)
                                <x-ui.button tone="ghost" size="sm" icon="pause-circle" class="size-9!" wire:click="confirmDeactivate({{ $question->id }})" wire:target="confirmDeactivate({{ $question->id }})" :aria-label="__('narrative_questions.deactivate_name', ['text' => $shortText])" />
                            @else
                                <x-ui.button tone="ghost" size="sm" icon="play-circle" class="size-9!" wire:click="reactivateQuestion({{ $question->id }})" wire:target="reactivateQuestion({{ $question->id }})" wire:loading.attr="disabled" :aria-label="__('narrative_questions.reactivate_name', ['text' => $shortText])" />
                            @endif
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! text-status-critical!" wire:click="confirmDelete({{ $question->id }})" wire:target="confirmDelete({{ $question->id }})" :aria-label="__('common.delete_name', ['name' => $shortText])" />
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
