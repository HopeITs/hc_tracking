<?php

declare(strict_types=1);

namespace App\Actions\NarrativeQuestions;

use App\Models\NarrativeQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a question no report template uses. A used question is deactivated
 * instead (NarrativeQuestion::isInUse(), wired to `report_template_questions`
 * in T33 slice 2, whose `narrative_question_id` nulls on delete).
 */
final class DeleteNarrativeQuestion
{
    /**
     * @throws ValidationException when a report template uses the question
     */
    public function handle(int $questionId): void
    {
        DB::transaction(function () use ($questionId): void {
            $question = NarrativeQuestion::query()->lockForUpdate()->findOrFail($questionId);

            Gate::authorize('delete', $question);

            if ($question->isInUse()) {
                throw ValidationException::withMessages(['question' => __('narrative_questions.in_use')]);
            }

            $question->delete();
        });
    }
}
