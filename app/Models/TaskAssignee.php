<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaskAssigneeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HR employee assigned to one task. A task can carry several of these.
 *
 * @property int $id
 * @property int $task_id
 * @property int $employee_id
 */
#[Fillable(['task_id', 'employee_id'])]
class TaskAssignee extends Model
{
    /** @use HasFactory<TaskAssigneeFactory> */
    use HasFactory;

    /**
     * The task this assignment belongs to.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The HR employee assigned. `employee_id` carries no foreign key — the
     * hr connection can't be reached with one — so this resolves with a
     * plain lookup query rather than a SQL join, same as Task::assignee()
     * did before multi-assignee.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
