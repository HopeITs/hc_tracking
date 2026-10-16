<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NarrativeQuestionFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\NarrativeQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A question in the org-wide narrative bank (contracts.md §12.4, D-43),
 * managed by MEAL (`narrative-questions.manage`). Each new report template
 * copies the active questions of both frequencies (T33 slice 2); a question
 * some template uses is deactivated, never deleted.
 *
 * `is_active` and `sort_order` are never mass assigned: they move only
 * through DeactivateNarrativeQuestion / ReactivateNarrativeQuestion and
 * MoveNarrativeQuestion. `sort_order` counts within a frequency.
 *
 * @property int $id
 * @property string $text_ar
 * @property string|null $text_en
 * @property NarrativeQuestionFrequency $frequency
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['text_ar', 'text_en', 'frequency'])]
class NarrativeQuestion extends Model
{
    /** @use HasFactory<NarrativeQuestionFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults so a question that has not been round-tripped
     * through the database still reports them under strict mode (the factory
     * leaves these columns to the database).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => NarrativeQuestionFrequency::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Questions a new report template still copies.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
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
     * Whether a report template uses this question, so it may only be
     * deactivated. Always false until `report_template_questions` (T33 slice
     * 2) exists; that slice wires its `narrative_question_id` in here.
     */
    public function isInUse(): bool
    {
        return false;
    }
}
