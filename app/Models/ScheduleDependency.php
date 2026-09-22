<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DependencyType;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link between two schedule items (milestones or tasks) of one project.
 *
 * @property int $id
 * @property int $project_id
 * @property string $predecessor_type
 * @property int $predecessor_id
 * @property string $successor_type
 * @property int $successor_id
 * @property DependencyType $type
 * @property int $lag_days
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'predecessor_type', 'predecessor_id', 'successor_type', 'successor_id', 'type', 'lag_days'])]
class ScheduleDependency extends Model
{
    /** @use HasFactory<ScheduleDependencyFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'fs',
        'lag_days' => 0,
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The predecessor's chart row id, e.g. "task-12".
     */
    public function predecessorRowId(): string
    {
        return $this->predecessor_type.'-'.$this->predecessor_id;
    }

    /**
     * The successor's chart row id, e.g. "milestone-4".
     */
    public function successorRowId(): string
    {
        return $this->successor_type.'-'.$this->successor_id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DependencyType::class,
            'lag_days' => 'integer',
            'predecessor_id' => 'integer',
            'successor_id' => 'integer',
        ];
    }
}
