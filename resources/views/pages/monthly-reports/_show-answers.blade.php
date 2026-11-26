{{-- The narrative questions of the month on ⚡show.blade.php (contracts §13.1 item 3, D-43a); answers save on blur and are optional on submit. A draft asks the template's live questions; a submitted or validated report lists the answers it kept, with the question text stored at answer time (D-41). --}}
@php
    $isDraft = $this->report->status === \App\Enums\MonthlyReportStatus::Draft;
    $questions = $this->questions;
    $answeredQuestions = $this->answeredQuestions;
    $fillsNarrative = $this->fillsNarrative();
    $storedAnswers = $this->report->answers->keyBy('report_template_question_id');
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
@endphp

<section aria-labelledby="monthly-report-answers-heading" class="flex flex-col gap-3" data-monthly-report-answers>
    <div>
        <h2 id="monthly-report-answers-heading" class="mrp-h2">{{ __('monthly_reports.questions_this_month') }}@if (! $isDraft && $answeredQuestions->isNotEmpty()) <span class="mrp-count">{{ $answeredQuestions->count() }}</span>@endif <span class="text-xs font-normal {{ $mutedText }}">{{ __('monthly_reports.optional') }}</span></h2>
        @if ($fillsNarrative && $questions->isNotEmpty())
            <p class="type-meta {{ $mutedText }}">{{ __('monthly_reports.answers_help') }}</p>
        @endif
    </div>

    @if (! $isDraft && $answeredQuestions->isEmpty())
        @include('pages.monthly-reports._show-empty-line', ['key' => 'answers', 'icon' => 'chat-bubble-left-right', 'heading' => __('monthly_reports.no_stored_answers'), 'body' => null])
    @elseif (! $isDraft)
        <ol class="mrp-qa-list" role="list">
            @foreach ($answeredQuestions as $storedAnswer)
                <li class="mrp-qa" wire:key="stored-answer-{{ $storedAnswer->id }}">
                    <div class="mrp-qa-head">
                        <p class="mrp-qa-question" dir="auto">{{ $storedAnswer->question_text }}</p>
                        @if ($storedAnswer->question?->frequency === \App\Enums\NarrativeQuestionFrequency::QuarterEnd)
                            <span class="mrp-pill mrp-pill-none">{{ __('monthly_reports.quarter_end_question') }}</span>
                        @endif
                    </div>

                    @if (filled($storedAnswer->answer))
                        <p class="mrp-qa-answer" dir="auto" x-data="{ full: false }" x-on:click="full = ! full" x-bind:class="{ 'mrp-clamp-3': ! full }">{{ $storedAnswer->answer }}</p>
                    @else
                        <p class="text-sm {{ $mutedText }}">{{ __('monthly_reports.no_answer') }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @elseif ($questions->isEmpty())
        @include('pages.monthly-reports._show-empty-line', ['key' => 'answers', 'icon' => 'chat-bubble-left-right', 'heading' => __('monthly_reports.no_questions'), 'body' => __('monthly_reports.no_questions_body')])
    @else
        <ol class="mrp-qa-list" role="list">
            @foreach ($questions as $question)
                <li class="mrp-qa" wire:key="question-{{ $question->id }}">
                    <div class="mrp-qa-head">
                        <label for="answer-{{ $question->id }}" class="mrp-qa-question" dir="auto">{{ $question->localizedText() }}</label>
                        @if ($question->frequency === \App\Enums\NarrativeQuestionFrequency::QuarterEnd)
                            <span class="mrp-pill mrp-pill-none">{{ __('monthly_reports.quarter_end_question') }}</span>
                        @endif
                    </div>

                    @if ($fillsNarrative)
                        <textarea
                            id="answer-{{ $question->id }}"
                            rows="2"
                            dir="auto"
                            wire:model.live.blur="answers.{{ $question->id }}"
                            class="field-sizing-content min-h-16 w-full max-w-3xl rounded-md border border-secondary-light bg-white px-2 py-1.5 text-sm text-secondary-dark outline-none focus:border-secondary-medium focus:ring-2 focus:ring-secondary-light dark:border-field-line dark:bg-surface-2 dark:text-ink dark:focus:border-ink-muted"
                        ></textarea>
                        @error('answers.'.$question->id)
                            <p class="text-xs text-status-critical">{{ $message }}</p>
                        @enderror
                    @elseif (filled($storedAnswers->get($question->id)?->answer))
                        <p class="mrp-qa-answer" dir="auto" x-data="{ full: false }" x-on:click="full = ! full" x-bind:class="{ 'mrp-clamp-3': ! full }">{{ $storedAnswers->get($question->id)->answer }}</p>
                    @else
                        <p class="text-sm {{ $mutedText }}">{{ __('monthly_reports.no_answer') }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
