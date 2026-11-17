<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Enums\NarrativeQuestionFrequency;
use App\Models\MonthlyReportAnswer;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use App\Notifications\ActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * MEAL adds or edits a narrative question of one report template
 * (contracts.md §12.4, Y-07, D-78). A new question is the template's own
 * (`narrative_question_id` null), active and last in its frequency. An edit
 * changes the two texts and, while the question has no answers, its
 * frequency (it then goes last there). The bank (`narrative_questions`) is
 * never written. The change applies to reports still in `draft`; submitted
 * answers keep the `question_text` stored when they were given (D-41).
 *
 * Records `report_template.question_added` / `.question_changed` on the
 * template, activity only (no notification), with the question id.
 */
final class SaveReportTemplateQuestion
{
    public function __construct(private ActivityRecorder $activities) {}

    /**
     * @param  array{text_ar: string, text_en?: ?string, frequency: string}  $data
     * @param  string|null  $expectedUpdatedAt  the question's `updated_at` as the editor read it; any other value is stale
     *
     * @throws ModelNotFoundException<ReportTemplateQuestion> when the question is not on this template
     * @throws ValidationException
     */
    public function handle(ReportTemplate $template, ?int $questionId, array $data, ?string $expectedUpdatedAt = null): ReportTemplateQuestion
    {
        return DB::transaction(function () use ($template, $questionId, $data, $expectedUpdatedAt): ReportTemplateQuestion {
            Gate::authorize('manage', $template);

            $question = $questionId === null
                ? new ReportTemplateQuestion
                : $template->questions()->whereKey($questionId)->lockForUpdate()->firstOrFail();

            if ($question->exists && $expectedUpdatedAt !== null && ! $this->isCurrent($question, $expectedUpdatedAt)) {
                throw ValidationException::withMessages(['question' => __('report_templates.stale')]);
            }

            $validated = $this->validate($data);
            $frequencyChanged = ! $question->exists || $question->frequency->value !== $validated['frequency'];

            if ($question->exists && $frequencyChanged && $this->hasAnswers($question)) {
                throw ValidationException::withMessages(['frequency' => __('report_templates.question_frequency_locked')]);
            }

            $question->fill($validated);

            if ($frequencyChanged) {
                $question->forceFill(['sort_order' => $this->nextPosition($template, $validated['frequency'])]);
            }

            if (! $question->exists) {
                $question->forceFill(['report_template_id' => $template->id, 'narrative_question_id' => null]);
                $question->save();

                $this->activities->record($template, 'report_template.question_added', properties: ['question_id' => $question->id], notify: false);

                return $question;
            }

            if ($question->isDirty()) {
                $question->save();

                $this->activities->record($template, 'report_template.question_changed', properties: ['question_id' => $question->id], notify: false);
            }

            return $question;
        });
    }

    /**
     * Whether the question is still as the editor read it.
     */
    private function isCurrent(ReportTemplateQuestion $question, string $expectedUpdatedAt): bool
    {
        if ($question->updated_at === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($expectedUpdatedAt)->equalTo($question->updated_at);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Changing when an answered question is asked would orphan its answers.
     */
    private function hasAnswers(ReportTemplateQuestion $question): bool
    {
        return MonthlyReportAnswer::query()->where('report_template_question_id', $question->id)->exists();
    }

    /**
     * The position after the last question of the frequency on this
     * template, with that frequency's rows locked in ascending id order.
     */
    private function nextPosition(ReportTemplate $template, string $frequency): int
    {
        $positions = $template->questions()
            ->where('frequency', $frequency)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('sort_order');

        return (int) $positions->max() + 1;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{text_ar: string, text_en: ?string, frequency: string}
     */
    private function validate(array $input): array
    {
        $trimmed = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        /** @var array{text_ar: string, text_en: ?string, frequency: string} */
        return Validator::make(
            [
                'text_ar' => $trimmed($input['text_ar'] ?? null),
                'text_en' => $trimmed($input['text_en'] ?? null),
                'frequency' => $trimmed($input['frequency'] ?? null),
            ],
            [
                'text_ar' => ['required', 'string', 'max:1000'],
                'text_en' => ['nullable', 'string', 'max:1000'],
                'frequency' => ['required', 'string', Rule::in(NarrativeQuestionFrequency::values())],
            ],
            [
                'text_ar.required' => __('narrative_questions.enter_arabic_text'),
                'text_ar.max' => __('narrative_questions.arabic_text_too_long'),
                'text_en.max' => __('narrative_questions.english_text_too_long'),
                'frequency.required' => __('narrative_questions.choose_frequency'),
                'frequency.in' => __('narrative_questions.choose_frequency'),
            ],
        )->validate();
    }
}
