<?php

declare(strict_types=1);

namespace App\Actions\NarrativeQuestions;

use App\Enums\NarrativeQuestionFrequency;
use App\Models\NarrativeQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a question of the narrative bank (contracts.md §12.4). Only
 * the two texts and the frequency come from the form: a new question is
 * active and goes last in its frequency, a question moved to the other
 * frequency goes last there, and the flag and the position otherwise move
 * only through their own actions.
 */
final class SaveNarrativeQuestion
{
    /**
     * @param  array{text_ar?: ?string, text_en?: ?string, frequency?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function handle(?int $questionId, array $input): NarrativeQuestion
    {
        if ($questionId === null) {
            Gate::authorize('create', NarrativeQuestion::class);
        } else {
            Gate::authorize('update', NarrativeQuestion::query()->findOrFail($questionId));
        }

        $validated = $this->validate($input);

        return DB::transaction(function () use ($questionId, $validated): NarrativeQuestion {
            $question = $questionId === null
                ? new NarrativeQuestion
                : NarrativeQuestion::query()->lockForUpdate()->findOrFail($questionId);

            $frequencyChanged = ! $question->exists || $question->frequency->value !== $validated['frequency'];

            $question->fill($validated);

            if ($frequencyChanged) {
                $last = NarrativeQuestion::query()->where('frequency', $validated['frequency'])->lockForUpdate()->max('sort_order');
                $question->forceFill(['sort_order' => (int) $last + 1]);
            }

            $question->save();

            return $question;
        });
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
