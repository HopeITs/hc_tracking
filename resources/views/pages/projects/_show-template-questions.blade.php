{{--
    The narrative questions panel of the "Report template" tab (Y-07 slice 2),
    mirroring the bank's page (admin/narrative-questions): rows grouped under
    Monthly and Quarter end, each with its position in the whole frequency (an
    LTR number), the question (dir="auto"), one status word (On / Switched
    off, a word and never a colour alone) and where it came from (From the
    bank / This report only). For a manager, Edit is the row's main action,
    Switch off / Switch on and Delete sit in the ⋯ menu, and up/down arrows
    reorder within the frequency; a viewer reads the same list with no
    control. Included by _show-template-tab.blade.php, which defines
    $templateItems and $canManageTemplate.
--}}
@use('App\Enums\NarrativeQuestionFrequency')
@use('Illuminate\Support\Str')

@php
    $templateQuestions = collect($templateItems['questions']);
    $questionsByFrequency = $templateQuestions->groupBy(fn (array $question): string => $question['frequency']->value);
    $coveredProposals = $templateItems['template']['proposals'];
@endphp

<x-ui.panel :title="__('report_templates.tab.questions')" :subtitle="__('report_templates.tab.questions_help')" :count="$templateQuestions->count()" :clip="false" data-template-questions-panel>
    @if ($canManageTemplate)
        <x-slot:toolbar>
            <x-ui.button tone="primary" size="sm" icon="plus" class="min-h-11 md:min-h-9" wire:click="openAddTemplateQuestion" wire:target="openAddTemplateQuestion" wire:loading.attr="disabled" data-test="template-add-question">
                {{ __('narrative_questions.add_question') }}
            </x-ui.button>
        </x-slot:toolbar>
    @endif

    <p class="sr-only" aria-live="polite" aria-atomic="true">{{ $templateQuestionAnnouncement }}</p>

    @if (count($coveredProposals) >= 2)
        <p class="border-b border-secondary-soft px-4 py-2.5 text-sm text-secondary-medium dark:border-line dark:text-ink-muted" data-test="template-questions-shared">
            {{ __('report_templates.tab.questions_shared', ['proposals' => collect($coveredProposals)->pluck('name')->implode(__('report_templates.tab.list_separator'))]) }}
        </p>
    @endif

    @if ($templateQuestions->isEmpty())
        <p class="p-3.5 text-sm italic text-secondary-medium dark:text-ink-muted">{{ __('report_templates.tab.questions_empty') }}</p>
    @else
        {{--
            After a move, focus returns to the moved question's arrow in the
            same direction, or to the other arrow when that one is now disabled
            (the row reached an end), so a keyboard user can keep pressing
            Enter. Flux re-enables the clicked button only after the request,
            hence the deferred focus.
        --}}
        <div
            class="flex flex-col gap-5 p-3.5 @6xl/detail:p-5"
            data-template-questions
            x-data="{
                refocus(questionId, direction) {
                    const row = this.$el.querySelector(`[data-template-question='${questionId}']`);
                    if (! row) {
                        return;
                    }
                    const wanted = row.querySelector(`[data-test='move-template-question-${direction}']`);
                    const other = row.querySelector(`[data-test='move-template-question-${direction === 'up' ? 'down' : 'up'}']`);
                    (wanted && ! wanted.disabled ? wanted : other)?.focus();
                },
            }"
            x-on:template-question-moved.window="$nextTick(() => setTimeout(() => refocus($event.detail.questionId, $event.detail.direction), 50))"
        >
            @foreach (NarrativeQuestionFrequency::cases() as $frequency)
                @continue(! $questionsByFrequency->has($frequency->value))

                @php
                    $groupQuestions = $questionsByFrequency->get($frequency->value);
                    $groupTotal = $groupQuestions->count();
                    $groupHeadingId = 'template-questions-group-'.$frequency->value;
                @endphp

                <section class="flex flex-col gap-2" aria-labelledby="{{ $groupHeadingId }}" wire:key="template-questions-group-{{ $frequency->value }}" data-frequency-group="{{ $frequency->value }}">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h3 id="{{ $groupHeadingId }}" class="text-sm font-bold text-secondary-dark dark:text-ink">{{ $frequency->label() }}</h3>
                        <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $groupTotal }}</span>
                        <p class="type-meta basis-full text-secondary-medium dark:text-ink-muted">{{ __('narrative_questions.frequency_help.'.$frequency->value) }}</p>
                    </div>

                    <ol class="flex flex-col divide-y divide-secondary-soft rounded-xl border border-secondary-soft dark:divide-line dark:border-line" aria-label="{{ __('narrative_questions.list_label', ['frequency' => $frequency->label()]) }}">
                        @foreach ($groupQuestions as $question)
                            @php
                                $shortText = Str::limit($question['text'], 80);
                            @endphp
                            <li wire:key="template-question-{{ $question['id'] }}" data-template-question="{{ $question['id'] }}" data-status="{{ $question['isActive'] ? 'on' : 'off' }}" @class(['flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3', 'opacity-70' => ! $question['isActive']])>
                                <span class="mt-0.5 w-8 shrink-0 text-center font-mono text-xs font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr" aria-label="{{ __('narrative_questions.position', ['position' => $question['position']]) }}">{{ $question['position'] }}</span>

                                <div class="min-w-48 flex-1 basis-60">
                                    <p class="text-sm font-semibold leading-snug break-words text-secondary-dark dark:text-ink" dir="auto">{{ $question['text'] }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                                        <span @class(['inline-flex items-center gap-1.5 font-semibold', 'text-status-good' => $question['isActive'], 'text-secondary-medium dark:text-ink-muted' => ! $question['isActive']]) data-test="template-question-status">
                                            <span aria-hidden="true" @class(['size-2 shrink-0 rounded-full', 'bg-status-good' => $question['isActive'], 'bg-secondary-light dark:bg-ink-faint' => ! $question['isActive']])></span>{{ __($question['isActive'] ? 'narrative_questions.status_on' : 'narrative_questions.status_off') }}
                                        </span>
                                        <span data-test="template-question-origin">{{ __($question['fromBank'] ? 'report_templates.tab.question_from_bank' : 'report_templates.tab.question_own') }}</span>
                                    </div>
                                </div>

                                @if ($canManageTemplate)
                                    <div class="ms-auto flex shrink-0 flex-wrap items-center gap-1">
                                        <x-ui.button
                                            tone="ghost" size="sm" icon="arrow-up" class="min-h-11 min-w-11 md:size-9! md:min-h-0 md:min-w-0"
                                            wire:click="moveTemplateQuestion({{ $question['id'] }}, 'up')"
                                            wire:target="moveTemplateQuestion"
                                            wire:loading.attr="disabled"
                                            :disabled="$question['position'] === 1"
                                            :aria-label="__('narrative_questions.move_up_name', ['text' => $shortText])"
                                            data-test="move-template-question-up"
                                        />
                                        <x-ui.button
                                            tone="ghost" size="sm" icon="arrow-down" class="min-h-11 min-w-11 md:size-9! md:min-h-0 md:min-w-0"
                                            wire:click="moveTemplateQuestion({{ $question['id'] }}, 'down')"
                                            wire:target="moveTemplateQuestion"
                                            wire:loading.attr="disabled"
                                            :disabled="$question['position'] === $groupTotal"
                                            :aria-label="__('narrative_questions.move_down_name', ['text' => $shortText])"
                                            data-test="move-template-question-down"
                                        />
                                        <x-ui.button size="sm" icon="pencil-square" class="min-h-11 md:min-h-9" wire:click="openEditTemplateQuestion({{ $question['id'] }})" :aria-label="__('common.edit_name', ['name' => $shortText])" data-test="edit-template-question-{{ $question['id'] }}">
                                            {{ __('narrative_questions.edit') }}
                                        </x-ui.button>

                                        <flux:dropdown align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="min-h-11 md:min-h-9" :aria-label="__('narrative_questions.more_actions_for_name', ['text' => $shortText])" data-test="template-question-menu-{{ $question['id'] }}" />
                                            <flux:menu>
                                                @if ($question['isActive'])
                                                    <flux:menu.item icon="pause-circle" wire:click="askSwitchOffTemplateQuestion({{ $question['id'] }})" data-test="switch-off-template-question-{{ $question['id'] }}">{{ __('narrative_questions.deactivate') }}</flux:menu.item>
                                                @else
                                                    <flux:menu.item icon="play-circle" wire:click="switchOnTemplateQuestion({{ $question['id'] }})" data-test="switch-on-template-question-{{ $question['id'] }}">{{ __('narrative_questions.reactivate') }}</flux:menu.item>
                                                @endif
                                                <flux:menu.item icon="trash" variant="danger" wire:click="askDeleteTemplateQuestion({{ $question['id'] }})" data-test="delete-template-question-{{ $question['id'] }}">{{ __('common.delete') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </div>
    @endif
</x-ui.panel>
