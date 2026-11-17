<?php

declare(strict_types=1);

namespace App\Actions\ReportTemplates;

use App\Models\ReportTemplate;
use App\Models\ReportTemplateQuestion;
use App\Notifications\ActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Switch a narrative question of one report template off or on (contracts.md
 * §12.4, Y-07). A switched-off question is no longer asked in draft reports;
 * an answer already typed stays in the table and shows again once the
 * question is switched back on. Submitted and validated reports keep their
 * answers (D-41). The bank is never written.
 *
 * Records `report_template.question_switched` on the template, activity only,
 * with the question id and the new state.
 */
final class SetReportTemplateQuestionActive
{
    public function __construct(private ActivityRecorder $activities) {}

    /**
     * @throws ModelNotFoundException<ReportTemplateQuestion> when the question is not on this template
     * @throws ValidationException when the question already has that state (someone else got there first)
     */
    public function handle(ReportTemplate $template, int $questionId, bool $active): void
    {
        DB::transaction(function () use ($template, $questionId, $active): void {
            Gate::authorize('manage', $template);

            $question = $template->questions()->whereKey($questionId)->lockForUpdate()->firstOrFail();

            if ($question->is_active === $active) {
                throw ValidationException::withMessages(['question' => __('report_templates.stale')]);
            }

            $question->forceFill(['is_active' => $active])->save();

            $this->activities->record($template, 'report_template.question_switched', properties: [
                'question_id' => $question->id,
                'active' => $active,
            ], notify: false);
        });
    }
}
