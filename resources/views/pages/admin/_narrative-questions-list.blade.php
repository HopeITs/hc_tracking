{{--
    The narrative question bank of admin/narrative-questions, one ordered list
    per frequency (a frequency with nothing to show is left out). Included by
    ⚡narrative-questions.blade.php, which defines $groups and $filtersAreActive.

    Each row reads as one block: its position within its frequency (an LTR
    number, counted in the whole frequency, not in a narrowed list), the
    question in the reader's language with the other language under it, then
    one status word (On / Switched off, a word and never a colour alone) and
    the frequency. Edit is the main action; switching on or off and delete sit
    in the ⋯ menu. Up/down are vertical, so they need no flipping in RTL; while
    a search or button narrows the list they are disabled, because a move is a
    move in the whole frequency. Each button names its question, and the
    page's live region announces the new position.
--}}
@use('Illuminate\Support\Str')

@php
    $readsArabic = app()->getLocale() === 'ar';
    $bankByFrequency = $this->questions->groupBy(fn ($question): string => $question->frequency->value);
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
            $total = $bankByFrequency->get($frequencyValue, collect())->count();
            $positions = $bankByFrequency->get($frequencyValue, collect())->pluck('id')->flip();
            $groupHeadingId = 'narrative-questions-group-'.$frequencyValue;
        @endphp

        <section aria-labelledby="{{ $groupHeadingId }}" wire:key="narrative-questions-group-{{ $frequencyValue }}" data-frequency-group="{{ $frequencyValue }}">
            <div class="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="{{ $groupHeadingId }}" class="text-base font-semibold">{{ $groupFrequency->label() }}</h2>
                <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $total }}</span>
                <p class="type-meta basis-full text-secondary-medium dark:text-ink-muted">{{ __('narrative_questions.frequency_help.'.$frequencyValue) }}</p>
            </div>

            <x-ui.panel
                as="ol"
                :clip="false"
                class="divide-y divide-secondary-soft dark:divide-line"
                aria-label="{{ __('narrative_questions.list_label', ['frequency' => $groupFrequency->label()]) }}"
            >
                @foreach ($groupQuestions as $question)
                    @php
                        $displayText = $question->localizedText();
                        $shortText = Str::limit($displayText, 80);
                        $position = $positions[$question->id] + 1;
                        $secondaryText = match (true) {
                            blank($question->text_en) => null,
                            $readsArabic => $question->text_en,
                            default => $question->text_ar,
                        };
                    @endphp
                    <li wire:key="narrative-question-{{ $question->id }}" data-narrative-question="{{ $question->id }}" data-status="{{ $question->status }}" @class(['flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $question->is_active])>
                        <span class="mt-0.5 w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('narrative_questions.position', ['position' => $position]) }}">{{ $position }}</span>

                        <div class="min-w-48 flex-1 basis-60">
                            <p class="text-[15px] font-semibold leading-snug break-words" dir="auto">{{ $displayText }}</p>
                            @if ($secondaryText !== null)
                                <p class="mt-0.5 text-sm break-words text-secondary-medium dark:text-ink-muted" dir="auto">{{ $secondaryText }}</p>
                            @endif
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                                <span @class(['inline-flex items-center gap-1.5 font-semibold', 'text-status-good' => $question->is_active, 'text-secondary-medium dark:text-ink-muted' => ! $question->is_active]) @if (! $question->is_active) data-test="narrative-question-inactive-badge" @endif>
                                    <span aria-hidden="true" @class(['size-2 shrink-0 rounded-full', 'bg-status-good' => $question->is_active, 'bg-secondary-light dark:bg-ink-faint' => ! $question->is_active])></span>{{ __($question->is_active ? 'narrative_questions.status_on' : 'narrative_questions.status_off') }}
                                </span>
                                <span data-test="narrative-question-frequency">{{ $question->frequency->label() }}</span>
                            </div>
                        </div>

                        <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                            {{-- A disabled button takes no pointer events, so the reason sits on this wrapper. --}}
                            <span class="flex items-center gap-1" @if ($filtersAreActive) title="{{ __('narrative_questions.clear_to_reorder') }}" @endif data-test="move-narrative-question-arrows">
                                <x-ui.button
                                    tone="ghost" size="sm" icon="arrow-up" class="min-h-11 min-w-11 md:size-9! md:min-h-0 md:min-w-0"
                                    wire:click="moveQuestion({{ $question->id }}, 'up')"
                                    wire:target="moveQuestion"
                                    wire:loading.attr="disabled"
                                    :disabled="$filtersAreActive || $position === 1"
                                    :aria-label="__('narrative_questions.move_up_name', ['text' => $shortText])"
                                    data-test="move-narrative-question-up"
                                />
                                <x-ui.button
                                    tone="ghost" size="sm" icon="arrow-down" class="min-h-11 min-w-11 md:size-9! md:min-h-0 md:min-w-0"
                                    wire:click="moveQuestion({{ $question->id }}, 'down')"
                                    wire:target="moveQuestion"
                                    wire:loading.attr="disabled"
                                    :disabled="$filtersAreActive || $position === $total"
                                    :aria-label="__('narrative_questions.move_down_name', ['text' => $shortText])"
                                    data-test="move-narrative-question-down"
                                />
                            </span>
                            <x-ui.button size="sm" icon="pencil-square" class="min-h-11 md:min-h-9" wire:click="openEditModal({{ $question->id }})" :aria-label="__('common.edit_name', ['name' => $shortText])" data-test="edit-narrative-question">
                                {{ __('narrative_questions.edit') }}
                            </x-ui.button>

                            <flux:dropdown align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="min-h-11 md:min-h-9" :aria-label="__('narrative_questions.more_actions_for_name', ['text' => $shortText])" data-test="narrative-question-menu-{{ $question->id }}" />
                                <flux:menu>
                                    @if ($question->is_active)
                                        <flux:menu.item icon="pause-circle" wire:click="confirmDeactivate({{ $question->id }})" data-test="deactivate-narrative-question">{{ __('narrative_questions.deactivate') }}</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="play-circle" wire:click="reactivateQuestion({{ $question->id }})" data-test="reactivate-narrative-question">{{ __('narrative_questions.reactivate') }}</flux:menu.item>
                                    @endif
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $question->id }})" data-test="delete-narrative-question">{{ __('common.delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </li>
                @endforeach
            </x-ui.panel>
        </section>
    @endforeach
</div>
