<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NarrativeQuestionFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\ReportTemplateQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A narrative question in one project's report template (contracts.md
 * §12.4): copied from the org-wide bank when the template is created, then
 * edited, reordered, added or deactivated by MEAL for that project only.
 * `narrative_question_id` points back at the bank question it came from (null
 * for a project's own question, or once the bank question is deleted).
 *
 * `sort_order` is fillable so the bank copy keeps its order; `is_active` is
 * not, and moves only through the template-question actions (slice 3).
 *
 * @property int $id
 * @property int $report_template_id
 * @property int|null $narrative_question_id
 * @property string $text_ar
 * @property string|null $text_en
 * @property NarrativeQuestionFrequency $frequency
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['narrative_question_id', 'text_ar', 'text_en', 'frequency', 'sort_order'])]
class ReportTemplateQuestion extends Model
{
    /** @use HasFactory<ReportTemplateQuestionFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults so a question that has not been round-tripped
     * through the database still reports them under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * The template this question belongs to.
     *
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /**
     * The bank question this was copied from, if any.
     *
     * @return BelongsTo<NarrativeQuestion, $this>
     */
    public function narrativeQuestion(): BelongsTo
    {
        return $this->belongsTo(NarrativeQuestion::class);
    }

    /**
     * Questions the project's reports still ask.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Grouped by frequency ('monthly' sorts before 'quarter_end'), each group
     * in the order MEAL set, with the id breaking ties.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('frequency'))
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * The text in the current language; a question with no English text shows
     * its Arabic text everywhere.
     */
    public function localizedText(): string
    {
        if (app()->getLocale() === 'ar' || blank($this->text_en)) {
            return $this->text_ar;
        }

        return $this->text_en;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_template_id' => 'integer',
            'narrative_question_id' => 'integer',
            'frequency' => NarrativeQuestionFrequency::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
