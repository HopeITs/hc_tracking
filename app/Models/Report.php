<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An actual value reported against a planned target.
 *
 * @property int $id
 * @property int $plan_indicator_id
 * @property int $user_id
 * @property ReportStatus $status
 * @property CarbonImmutable $planned_date
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string $value
 * @property string|null $narrative_report_notes
 * @property string|null $executive_summary
 * @property string|null $key_achievements
 * @property string|null $challenges
 * @property string|null $lessons_learned
 * @property string|null $next_period_plans
 * @property CarbonImmutable|null $submit_date
 * @property bool $is_seen_by_meal
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'plan_indicator_id',
    'user_id',
    'status',
    'planned_date',
    'period_start',
    'period_end',
    'value',
    'narrative_report_notes',
    'executive_summary',
    'key_achievements',
    'challenges',
    'lessons_learned',
    'next_period_plans',
    'submit_date',
    'is_seen_by_meal',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults so a report that has not been round-tripped
     * through the database still reports its status and MEAL visibility.
     *
     * ReportFactory omits both defaulted columns on purpose, so without this
     * `Report::factory()->create()->status` raises MissingAttributeException
     * under strict mode — the same trap Account::$is_active documents.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_seen_by_meal' => false,
    ];

    /**
     * The target this actual was reported against.
     *
     * @return BelongsTo<PlanIndicator, $this>
     */
    public function planIndicator(): BelongsTo
    {
        return $this->belongsTo(PlanIndicator::class);
    }

    /**
     * The user who submitted this report.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The means of verification uploaded with this report.
     *
     * @return HasMany<ReportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }

    /**
     * Whether this report may still be edited.
     *
     * Derived from status rather than stored. The dropped `is_editable` column
     * could disagree with the workflow state; this cannot.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Whether this report is late: still a draft after the date it covers.
     *
     * The one definition of "reporting overdue" shared by every page, and by
     * the overdue() scope below.
     */
    public function isOverdue(): bool
    {
        return $this->status === ReportStatus::Draft && $this->planned_date->lessThan(CarbonImmutable::today());
    }

    /**
     * Limit the query to drafts past the date they cover.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', ReportStatus::Draft->value)->whereDate('planned_date', '<', CarbonImmutable::today());
    }

    /**
     * Limit the query to reports that have cleared review.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', ReportStatus::Approved->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'planned_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'value' => 'decimal:2',
            'submit_date' => 'date',
            'is_seen_by_meal' => 'boolean',
        ];
    }
}
