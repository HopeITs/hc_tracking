{{-- The narrative questions of the month on ⚡show.blade.php (contracts §13.1 item 3, D-43a); answers save on blur and are optional on submit. --}}
@php
    $questions = $this->questions;
    $fillsNarrative = $this->fillsNarrative();
    $storedAnswers = $this->report->answers->keyBy('report_template_question_id');
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
@endphp

<section aria-labelledby="monthly-report-answers-heading" class="flex flex-col gap-3" data-monthly-report-answers>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="monthly-report-answers-heading" class="text-base font-bold">{{ __('monthly_reports.questions_this_month') }} <span class="text-xs font-normal {{ $mutedText }}">{{ __('monthly_reports.optional') }}</span></h2>
            @if ($fillsNarrative && $questions->isNotEmpty())
                <p class="type-meta {{ $mutedText }}">{{ __('monthly_reports.answers_help') }}</p>
            @endif
        </div>

        @if ($this->canDraftWithAi())
            <div class="flex flex-col items-end gap-1" data-ai-draft>
                @if ($this->aiDraftKey !== null)
                    <p wire:poll.4s="collectAiDraft" class="flex items-center gap-1.5 text-sm {{ $mutedText }}" aria-live="polite" data-ai-draft-pending>
                        <flux:icon.arrow-path variant="micro" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        {{ __('monthly_reports.ai_drafting') }}
                    </p>
                @else
                    <x-ui.button
                        icon="sparkles"
                        wire:click="draftNarrativeWithAi"
                        wire:loading.attr="disabled"
                        wire:target="draftNarrativeWithAi,answers"
                        data-test="ai-draft-button"
                    >{{ __('monthly_reports.ai_draft') }}</x-ui.button>
                    <p class="text-xs {{ $mutedText }}">{{ __('monthly_reports.ai_draft_help') }}</p>
                @endif
            </div>
        @endif
    </div>

    @if ($questions->isEmpty())
        <x-ui.empty-state icon="chat-bubble-left-right" :heading="__('monthly_reports.no_questions')" :body="__('monthly_reports.no_questions_body')" />
    @else
        <ol class="flex flex-col border-t border-secondary-soft dark:border-line" role="list">
            @foreach ($questions as $question)
                <li class="flex flex-col gap-2 border-b border-secondary-soft py-4 dark:border-line" wire:key="question-{{ $question->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="answer-{{ $question->id }}" class="font-semibold" dir="auto">{{ $question->localizedText() }}</label>
                        @if ($question->frequency === \App\Enums\NarrativeQuestionFrequency::QuarterEnd)
                            <span class="rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold dark:border-line-strong">{{ __('monthly_reports.quarter_end_question') }}</span>
                        @endif
                    </div>

                    @if ($fillsNarrative)
                        <textarea
                            id="answer-{{ $question->id }}"
                            rows="2"
                            dir="auto"
                            wire:model.blur="answers.{{ $question->id }}"
                            class="field-sizing-content min-h-16 w-full max-w-3xl rounded-md border border-secondary-light bg-white px-2 py-1.5 text-sm text-secondary-dark outline-none focus:border-secondary-medium focus:ring-2 focus:ring-secondary-light dark:border-field-line dark:bg-surface-2 dark:text-ink dark:focus:border-ink-muted"
                        ></textarea>
                        @error('answers.'.$question->id)
                            <p class="text-xs text-status-critical">{{ $message }}</p>
                        @enderror

                        @if ($aiDraft = $this->aiDrafts->get($question->id))
                            <div class="flex flex-wrap items-center gap-2 text-xs {{ $mutedText }}" data-ai-draft-rating="{{ $question->id }}">
                                <flux:icon.sparkles variant="micro" class="size-3.5" aria-hidden="true" />
                                <span>{{ __('monthly_reports.ai_drafted_label') }}</span>
                                <span aria-hidden="true">·</span>
                                @if ($aiDraft->rating === null)
                                    <span>{{ __('monthly_reports.ai_draft_ask') }}</span>
                                    <button type="button" wire:click="rateAiDraft({{ $question->id }}, 'helpful')" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold hover:bg-secondary-soft focus-visible:outline-2 dark:hover:bg-surface-3" data-test="ai-draft-helpful">
                                        <flux:icon.hand-thumb-up variant="micro" class="size-3.5" aria-hidden="true" />{{ __('monthly_reports.ai_draft_helpful') }}
                                    </button>
                                    <button type="button" wire:click="rateAiDraft({{ $question->id }}, 'not_helpful')" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold hover:bg-secondary-soft focus-visible:outline-2 dark:hover:bg-surface-3" data-test="ai-draft-not-helpful">
                                        <flux:icon.hand-thumb-down variant="micro" class="size-3.5" aria-hidden="true" />{{ __('monthly_reports.ai_draft_not_helpful') }}
                                    </button>
                                @else
                                    <span>{{ __('monthly_reports.ai_draft_rated') }}</span>
                                @endif
                            </div>
                        @endif
                    @elseif (filled($storedAnswers->get($question->id)?->answer))
                        <p class="text-sm whitespace-pre-line" dir="auto">{{ $storedAnswers->get($question->id)->answer }}</p>
                    @else
                        <p class="text-sm {{ $mutedText }}">{{ __('monthly_reports.no_answer') }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
