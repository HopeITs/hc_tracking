<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasTree;
use App\Enums\MilestoneStatus;
use App\Observers\MilestoneObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * @property MilestoneStatus $status
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
#[ObservedBy(MilestoneObserver::class)]
class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    use HasTree;

    /**
     * The column pointing at the milestone this one is nested under.
     */
    public static function parentKeyName(): string
    {
        return 'parent_milestone_id';
    }

    /**
     * Milestones within a project are ordered by their planned sequence.
     */
    public static function childrenOrderColumn(): ?string
    {
        return 'sequence';
    }

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
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'actual_date' => 'date',
            'status' => MilestoneStatus::class,
            'sequence' => 'integer',
            'weight_percentage' => 'decimal:2',
        ];
    }
}
