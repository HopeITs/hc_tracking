<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Enums\MonthlyReportStatus;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One project's report for one month (contracts §13). Its lines come from
 * the project's confirmed report template; its figures reach `reports` as
 * mirrors, one per line share, so PlanIndicator::achievedValue() stays the
 * one achievement definition. Status moves only through the T17A actions.
 *
 * @property int $id
 * @property string $slug
 * @property int $project_id
 * @property CarbonImmutable $period
 * @property MonthlyReportStatus $status
 * @property int|null $prepared_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $validated_at
 * @property int|null $validated_by
 * @property CarbonImmutable|null $returned_at
 * @property int|null $returned_by
 * @property string|null $return_reason
 * @property CarbonImmutable|null $seen_by_meal_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'period',
    'status',
    'prepared_by',
    'submitted_at',
    'submitted_by',
    'validated_at',
    'validated_by',
    'returned_at',
    'returned_by',
    'return_reason',
    'seen_by_meal_at',
])]
class MonthlyReport extends Model implements TracksActivity
{
    /** @use HasFactory<MonthlyReportFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    /**
     * Mirror the column default so a report that has not been round-tripped
     * through the database still reports its status under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * "project-slug-2026-10". The project is read by id rather than through
     * the relation, so creating a report never lazy-loads it.
     */
    protected function slugSource(): string
    {
        $projectSlug = $this->relationLoaded('project')
            ? $this->project?->slug
            : Project::query()->whereKey($this->project_id)->value('slug');

        return $projectSlug.'-'.$this->period->format('Y-m');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<MonthlyReportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MonthlyReportLine::class);
    }

    /**
     * @return HasMany<MonthlyReportActivityEntry, $this>
     */
    public function activityEntries(): HasMany
    {
        return $this->hasMany(MonthlyReportActivityEntry::class);
    }

    /**
     * @return HasMany<MonthlyReportReopenRequest, $this>
     */
    public function reopenRequests(): HasMany
    {
        return $this->hasMany(MonthlyReportReopenRequest::class);
    }

    /**
     * The `reports` rows mirroring this report's shares.
     *
     * @return HasMany<Report, $this>
     */
    public function mirrors(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Whether the manager may still change the report.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'period' => 'date',
            'status' => MonthlyReportStatus::class,
            'prepared_by' => 'integer',
            'submitted_at' => 'datetime',
            'submitted_by' => 'integer',
            'validated_at' => 'datetime',
            'validated_by' => 'integer',
            'returned_at' => 'datetime',
            'returned_by' => 'integer',
            'seen_by_meal_at' => 'datetime',
        ];
    }

    public function activityModule(): string
    {
        return 'monthly-reports';
    }

    public function activityProgramId(): ?int
    {
        return Project::programIdFor($this->activityAttribute('project_id'));
    }

    /**
     * "Project name · 2026-10", in English like every stored label.
     */
    public function activityLabel(): string
    {
        $name = (string) Project::query()->whereKey($this->activityAttribute('project_id'))->value('name');
        $period = $this->activityAttribute('period');

        return $name.' · '.CarbonImmutable::parse($period)->format('Y-m');
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'seen_by_meal_at' => 'seen_by_meal',
        ];
    }

    /**
     * Draft → submitted is the submission, submitted → validated the
     * validation, submitted → draft a return. A granted reopen (validated →
     * draft) is announced by the reopen decision, not here.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        $from = $old instanceof MonthlyReportStatus ? $old->value : $old;
        $to = $new instanceof MonthlyReportStatus ? $new->value : $new;

        return match (true) {
            $field === 'status' && $from === 'draft' && $to === 'submitted' => 'submitted',
            $field === 'status' && $from === 'submitted' && $to === 'validated' => 'validated',
            $field === 'status' && $from === 'submitted' && $to === 'draft' => 'returned',
            $field === 'seen_by_meal_at' => $old === null && $new !== null ? 'seen_by_meal' : null,
            default => null,
        };
    }
}
