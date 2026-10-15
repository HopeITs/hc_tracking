<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\RagStatus;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $result_id
 * @property int|null $library_indicator_id
 * @property int|null $copied_from_id
 * @property string|null $code
 * @property int|null $project_id
 * @property IndicatorMeasure $measure
 * @property string|null $unit
 * @property string|null $baseline
 * @property string|null $target
 * @property Period $frequency
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'result_id', 'library_indicator_id', 'copied_from_id', 'code', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency'])]
class Indicator extends Model implements TracksActivity
{
    /** @use HasFactory<IndicatorFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Mirror the column defaults so an indicator that has not been
     * round-tripped through the database still reports its measure and
     * frequency.
     *
     * Both columns carry database defaults and IndicatorFactory omits them, so
     * without this, reading either on a freshly created model raises
     * MissingAttributeException under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'measure' => 'numeric',
        'frequency' => 'quarterly',
    ];

    /**
     * The result this indicator measures.
     *
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * The Indicator Library entry this indicator was picked from, if any.
     *
     * `code` is a read-only copy of the entry's code and `measure` mirrors
     * the entry's measure (SaveLibraryIndicator keeps it in step).
     *
     * @return BelongsTo<LibraryIndicator, $this>
     */
    public function libraryIndicator(): BelongsTo
    {
        return $this->belongsTo(LibraryIndicator::class);
    }

    /**
     * The program-framework indicator this one was copied from
     * (CopyResultTree); its `target` is this indicator's default target.
     *
     * @return BelongsTo<self, $this>
     */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    /**
     * The targets planned against this indicator.
     *
     * @return HasMany<PlanIndicator, $this>
     */
    public function planIndicators(): HasMany
    {
        return $this->hasMany(PlanIndicator::class);
    }

    /**
     * The project this indicator is scoped to, if any.
     *
     * Nullable: most indicators sit at outcome level and belong to no single
     * project. `result_id` stays required, so an indicator always measures
     * something.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The dated readings recorded against this indicator, oldest first.
     *
     * Ordered here rather than at every call site, because a trend series read
     * out of order is a bug that renders as a scribble rather than an error.
     *
     * @return HasMany<IndicatorProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(IndicatorProgress::class)->orderBy('date')->orderBy('id');
    }

    /**
     * The most recent recorded value, or null when nothing has been recorded.
     *
     * Answers from a loaded `progress` relation when there is one, so rendering
     * a list of indicators does not fire a query per row.
     */
    public function latestValue(): ?float
    {
        if ($this->relationLoaded('progress')) {
            $latest = $this->getRelation('progress')->last();

            return $latest === null ? null : (float) $latest->value;
        }

        $value = $this->progress()->reorder()->orderByDesc('date')->orderByDesc('id')->value('value');

        return $value === null ? null : (float) $value;
    }

    /**
     * Percent of the lifetime target reached, measured from the baseline.
     *
     * Progress is measured as movement from baseline to target, not as a raw
     * share of the target: an indicator starting at 40 and targeting 80 is half
     * done at 60, not three quarters done.
     *
     * Returns null when there is no target, when the target equals the
     * baseline (no movement is being asked for), or when nothing has been
     * recorded — the caller renders an em dash rather than a misleading zero.
     */
    public function percentAchieved(): ?float
    {
        if ($this->target === null) {
            return null;
        }

        $latest = $this->latestValue();

        if ($latest === null) {
            return null;
        }

        $baseline = (float) ($this->baseline ?? 0);
        $span = (float) $this->target - $baseline;

        if ($span === 0.0) {
            return null;
        }

        return (($latest - $baseline) / $span) * 100;
    }

    /**
     * How this indicator is tracking, given how much of a window has elapsed.
     *
     * The window is passed in rather than stored, because the same indicator is
     * rated against a fiscal year on one page and against a project period on
     * another. Returns null when achievement cannot be computed.
     */
    public function ragStatus(DateTimeInterface $windowStart, DateTimeInterface $windowEnd, ?DateTimeInterface $asOf = null): ?RagStatus
    {
        $achieved = $this->percentAchieved();

        if ($achieved === null) {
            return null;
        }

        $start = $windowStart->getTimestamp();
        $end = $windowEnd->getTimestamp();
        $now = ($asOf ?? now())->getTimestamp();

        if ($end <= $start) {
            return RagStatus::rate($achieved, 100.0);
        }

        $elapsed = (($now - $start) / ($end - $start)) * 100;

        return RagStatus::rate($achieved, min(max($elapsed, 0.0), 100.0));
    }

    /**
     * Get the attributes that should be cast.
     *
     * `baseline` and `target` cast to decimal strings rather than floats,
     * matching plan_indicators.target — money and measurement columns are not
     * compared as floats anywhere in this schema.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'measure' => IndicatorMeasure::class,
            'frequency' => Period::class,
            'baseline' => 'decimal:2',
            'target' => 'decimal:2',
        ];
    }

    public function activityModule(): string
    {
        return 'indicators';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    /**
     * The program of its result, else of its project.
     */
    public function activityProgramId(): ?int
    {
        $programId = Result::query()->whereKey($this->activityAttribute('result_id'))->value('program_id');

        return $programId !== null ? (int) $programId : Project::programIdFor($this->activityAttribute('project_id'));
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [
            'target' => 'target_changed',
            'name' => 'updated',
            'baseline' => 'updated',
            'unit' => 'updated',
            'measure' => 'updated',
            'frequency' => 'updated',
        ];
    }

    /**
     * The project owner, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_filter([Project::ownerEmployeeIdFor($this->activityAttribute('project_id'))])];
    }
}
