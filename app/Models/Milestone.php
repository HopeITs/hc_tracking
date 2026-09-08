<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $planned_date
 * @property CarbonImmutable|null $actual_date
 * @property string $status
 * @property int $sequence
 * @property string $weight_percentage
 * @property int $responsible_user_id
 * @property int|null $parent_milestone_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'name',
    'description',
    'planned_date',
    'actual_date',
    'status',
    'sequence',
    'weight_percentage',
    'responsible_user_id',
    'parent_milestone_id',
])]
class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    /**
     * The project this milestone belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The user accountable for reaching this milestone.
     *
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * The milestone this one is nested under.
     *
     * @return BelongsTo<Milestone, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Milestone::class, 'parent_milestone_id');
    }

    /**
     * The milestones nested under this one.
     *
     * @return HasMany<Milestone, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Milestone::class, 'parent_milestone_id');
    }

    /**
     * The tasks that deliver this milestone.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'actual_date' => 'date',
            'sequence' => 'integer',
            'weight_percentage' => 'decimal:2',
        ];
    }
}
