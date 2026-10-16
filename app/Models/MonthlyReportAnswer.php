<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A narrative answer of a monthly report (contracts §13, D-43a). Written
 * only by SaveMonthlyReportAnswers; `question_text` is the question as it
 * read when answered.
 *
 * @property int $id
 * @property int $monthly_report_id
 * @property int|null $report_template_question_id
 * @property string $question_text
 * @property string|null $answer
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['monthly_report_id', 'report_template_question_id', 'question_text', 'answer'])]
class MonthlyReportAnswer extends Model
{
    /** @use HasFactory<MonthlyReportAnswerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * @return BelongsTo<ReportTemplateQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateQuestion::class, 'report_template_question_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'monthly_report_id' => 'integer',
            'report_template_question_id' => 'integer',
        ];
    }
}
