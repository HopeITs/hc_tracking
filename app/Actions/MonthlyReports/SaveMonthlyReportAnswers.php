<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportAnswer;
use App\Models\ReportTemplateQuestion;
use App\Support\MonthlyReportGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Save the narrative answers of a draft (contracts §13, §13.1 item 3,
 * D-43a). Each answer names an active question of the project's template
 * asked in the report's month; one other id rejects the whole batch. The
 * question's text is copied at answer time. Answers are optional on submit.
 */
final class SaveMonthlyReportAnswers
{
    /**
     * @param  list<array{question_id: int, answer: ?string}>  $answers
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, array $answers): MonthlyReport
    {
        return DB::transaction(function () use ($report, $expectedStatus, $answers): MonthlyReport {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'update');

            Validator::make(['answers' => $answers], [
                'answers' => ['array', 'list'],
                'answers.*.question_id' => ['required', 'integer', 'distinct'],
                'answers.*.answer' => ['nullable', 'string', 'max:5000'],
            ])->validate();

            $ids = array_map(fn (array $answer): int => (int) $answer['question_id'], $answers);
            $questions = self::questions($locked)->whereKey($ids)->get()->keyBy('id');

            if ($questions->count() !== count($ids)) {
                throw (new ModelNotFoundException)->setModel(ReportTemplateQuestion::class, array_values(array_diff($ids, $questions->modelKeys())));
            }

            foreach ($answers as $answer) {
                $question = $questions->get((int) $answer['question_id']);
                $text = trim((string) ($answer['answer'] ?? ''));

                MonthlyReportAnswer::query()->updateOrCreate(
                    ['monthly_report_id' => $locked->id, 'report_template_question_id' => $question->id],
                    ['question_text' => $question->text_ar, 'answer' => $text === '' ? null : $text],
                );
            }

            return $locked;
        });
    }

    /**
     * The questions the report asks: active questions of its project's
     * template asked in its month, in MEAL's order.
     *
     * @return Builder<ReportTemplateQuestion>
     */
    public static function questions(MonthlyReport $report): Builder
    {
        return ReportTemplateQuestion::query()
            ->whereHas('template', fn (Builder $query) => $query->where('project_id', $report->project_id))
            ->active()
            ->askedIn(CarbonImmutable::parse($report->period)->startOfMonth())
            ->ordered();
    }
}
