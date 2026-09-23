<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsParentActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleBaselineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A frozen copy of a project's schedule, so later slippage can be measured
 * against what was agreed (MS Project's "Save baseline").
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'name', 'created_by'])]
class ScheduleBaseline extends Model
{
    /** @use HasFactory<ScheduleBaselineFactory> */
    use HasFactory, RecordsParentActivity;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ScheduleBaselineItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ScheduleBaselineItem::class);
    }

    public function activityParent(): ?Project
    {
        return $this->project()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'project.baseline_saved' : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return ['baseline' => (string) $this->name];
    }
}
