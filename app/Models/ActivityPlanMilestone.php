<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityPlanMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $completed_at
 * @property int|null $created_by
 */
#[Fillable(['activity_plan_id', 'plan_indicator_id', 'created_by', 'title', 'description', 'due_date', 'measure', 'threshold', 'completed_at'])]
class ActivityPlanMilestone extends Model
{
    /** @use HasFactory<ActivityPlanMilestoneFactory> */
    use HasFactory;

    /** @return BelongsTo<ActivityPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActivityPlan::class, 'activity_plan_id');
    }

    /** @return BelongsTo<PlanIndicator, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PlanIndicator::class, 'plan_indicator_id');
    }

    /**
     * The tasks raised to reach this milestone.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'activity_plan_milestone_id')->orderByRaw('due_date is null, due_date')->orderBy('id');
    }

    public function progress(?CarbonImmutable $now = null): ?float
    {
        if ($this->measure === 'checkpoint') {
            return $this->completed_at === null ? 0.0 : 1.0;
        }

        $target = $this->target;

        if ($target === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $through = $now->lessThan($this->due_date) ? $now : $this->due_date;
        $actual = $target->achievedValue($through);

        if ($this->measure === 'percentage') {
            return (float) $target->target > 0 ? $actual / (float) $target->target * 100 : null;
        }

        return $actual;
    }

    public function status(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $progress = $this->progress($now);
        $threshold = $this->measure === 'checkpoint' ? 1.0 : (float) $this->threshold;

        if ($progress !== null && $progress >= $threshold) {
            return 'reached';
        }

        return $now->greaterThanOrEqualTo($this->due_date->setTime(16, 30)) ? 'missed' : 'upcoming';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_date' => 'immutable_date', 'completed_at' => 'immutable_datetime', 'threshold' => 'decimal:2'];
    }
}
