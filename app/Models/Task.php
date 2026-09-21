<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $milestone_id
 * @property int|null $parent_task_id
 * @property int $proposal_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $completed_date
 * @property int $progress_percentage
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'milestone_id',
    'parent_task_id',
    'proposal_id',
    'title',
    'description',
    'status',
    'priority',
    'start_date',
    'due_date',
    'completed_date',
    'progress_percentage',
    'created_by',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * The milestone this task contributes to, if any.
     *
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * The proposal this task is carried out under.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The task this one is a subtask of, if any. Subtasks are a single
     * level deep — a subtask cannot itself carry subtasks, enforced in
     * the task-management components rather than the schema.
     *
     * @return BelongsTo<Task, $this>
     */
    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /**
     * The subtasks carried out under this task.
     *
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * The HR employees assigned to this task. A task can have several.
     *
     * @return HasMany<TaskAssignee, $this>
     */
    public function taskAssignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    /**
     * The user who raised this task.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The files attached to this task.
     *
     * @return HasMany<TaskAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_date' => 'date',
            'progress_percentage' => 'integer',
        ];
    }
}
