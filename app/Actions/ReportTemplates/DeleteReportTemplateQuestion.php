<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Models\MonthlyReportAnswer;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use App\Notifications\ActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a narrative question of one report template that no monthly report
 * has answered (contracts.md §12.4, Y-07). An answered question is switched
 * off instead (SetReportTemplateQuestionActive). The bank is never written.
 *
 * Records `report_template.question_removed` on the template, activity only,
 * with the question id.
 */
final class DeleteReportTemplateQuestion
{
    public function __construct(private ActivityRecorder $activities) {}

    /**
     * @throws ModelNotFoundException<ReportTemplateQuestion> when the question is not on this template
     * @throws ValidationException when a monthly report answer points at the question
     */
    public function handle(ReportTemplate $template, int $questionId): void
    {
        DB::transaction(function () use ($template, $questionId): void {
            Gate::authorize('manage', $template);

            $question = $template->questions()->whereKey($questionId)->lockForUpdate()->firstOrFail();

            if (MonthlyReportAnswer::query()->where('report_template_question_id', $question->id)->exists()) {
                throw ValidationException::withMessages(['question' => __('report_templates.question_has_answers')]);
            }

            $question->delete();

            $this->activities->record($template, 'report_template.question_removed', properties: ['question_id' => $question->id], notify: false);
        });
    }
}
