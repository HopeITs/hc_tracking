<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Notifications\EmployeeRecipients;
use App\Notifications\TracksActivity;
use App\Notifications\UnreadCounts;
use Carbon\CarbonImmutable;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One time a person was put in a "responsible" field of a record (a project's
 * owner, ...). The assignee can acknowledge it, with an optional note, or
 * reject it with a reason; a rejection goes to the assigner and the program
 * manager (and the top level when a program manager rejects) and locks the
 * record. Rows are never deleted: the
 * set of rows for a field is the audit trail of who was asked and what they
 * said, and only the latest non-superseded row is "current".
 *
 * Decisions are recorded as activity on the assignable record, so they reach
 * the right people through the ordinary notification pipeline.
 *
 * @property int $id
 * @property string $assignable_type
 * @property int $assignable_id
 * @property string $field
 * @property int $employee_id
 * @property int|null $assigned_by
 * @property AssignmentStatus $status
 * @property CarbonImmutable|null $seen_at
 * @property CarbonImmutable|null $responded_at
 * @property int|null $responded_by
 * @property string|null $response_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['assignable_type', 'assignable_id', 'field', 'employee_id', 'assigned_by', 'status', 'seen_at', 'responded_at', 'responded_by', 'response_note'])]
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    /**
     * The record the person was assigned on.
     *
     * @return MorphTo<Model, $this>
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The HR employee who was assigned.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /**
     * The user who made the assignment.
     *
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The user who acknowledged or rejected it.
     *
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /**
     * Assignments still waiting for the assignee's decision.
     *
     * @param  Builder<Assignment>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', AssignmentStatus::Pending->value);
    }

    /**
     * Assignments that still stand (not superseded).
     *
     * @param  Builder<Assignment>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', '!=', AssignmentStatus::Superseded->value);
    }

    /**
     * The pending assignment a user was asked to decide on for a record, if any.
     */
    public static function pendingFor(User $user, string $assignableType, int $assignableId): ?self
    {
        if ($user->employee_id === null) {
            return null;
        }

        return static::query()
            ->pending()
            ->where('assignable_type', $assignableType)
            ->where('assignable_id', $assignableId)
            ->where('employee_id', $user->employee_id)
            ->latest('id')
            ->first();
    }

    /**
     * Whether this user is the person the assignment was made to.
     */
    public function isFor(User $user): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $this->employee_id;
    }

    /**
     * Remember that the assignee has opened it. Only ever set once.
     */
    public function markSeen(): void
    {
        if ($this->seen_at === null) {
            $this->forceFill(['seen_at' => now()])->save();
        }

        $this->syncAssigneeNotifications(markRead: true, stripActions: false);
    }

    /**
     * The assignee accepts, optionally with a note ("until the 15th I am away").
     */
    public function acknowledge(User $user, ?string $note = null): void
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        $this->respond(AssignmentStatus::Acknowledged, $user, $note);
        $this->syncAssigneeNotifications(markRead: true, stripActions: true);
        $this->recordOnAssignable('assignment_acknowledged', $note);
    }

    /**
     * The assignee declines. The reason is mandatory: it is what the program
     * manager reads when deciding what to do next.
     */
    public function reject(User $user, string $reason): void
    {
        $reason = trim($reason);

        $this->respond(AssignmentStatus::Rejected, $user, $reason);
        $this->syncAssigneeNotifications(markRead: true, stripActions: true);
        $this->recordOnAssignable('assignment_rejected', $reason);
    }

    /**
     * Close this assignment because the field was given to someone else (or
     * emptied): the assignee's notification stops offering acknowledge / reject.
     */
    public function supersede(): void
    {
        $this->forceFill(['status' => AssignmentStatus::Superseded])->save();
        $this->syncAssigneeNotifications(markRead: false, stripActions: true);
    }

    /**
     * Keep the assignee's inbox in step with the assignment, so acting on the
     * page and acting on the notification always agree: their notifications
     * about this record that carry acknowledge / reject are read once they
     * have seen or answered it, and lose the buttons once it is answered or
     * superseded.
     */
    private function syncAssigneeNotifications(bool $markRead, bool $stripActions): void
    {
        $user = User::query()->where('employee_id', $this->employee_id)->first(['id', 'employee_id']);

        if ($user === null) {
            return;
        }

        $rows = UserNotification::query()
            ->forUser($user)
            ->where('subject_type', $this->assignable_type)
            ->where('subject_id', $this->assignable_id)
            ->get()
            ->filter(fn (UserNotification $row): bool => $this->offersAssignmentActions($row));

        foreach ($rows as $row) {
            $data = $row->data;

            if ($stripActions) {
                $data['actions'] = array_values(array_filter(
                    $data['actions'] ?? [],
                    fn (array $action): bool => ! str_starts_with((string) ($action['type'] ?? ''), 'assignment.'),
                ));
            }

            $row->forceFill([
                'data' => $data,
                'read_at' => $markRead ? ($row->read_at ?? now()) : $row->read_at,
            ])->save();
        }

        if ($rows->isNotEmpty()) {
            app(UnreadCounts::class)->forget($user->id);
        }
    }

    private function offersAssignmentActions(UserNotification $row): bool
    {
        foreach ($row->data['actions'] ?? [] as $action) {
            if (str_starts_with((string) ($action['type'] ?? ''), 'assignment.')) {
                return true;
            }
        }

        return false;
    }

    private function respond(AssignmentStatus $status, User $user, ?string $note): void
    {
        $this->forceFill([
            'status' => $status,
            'responded_at' => now(),
            'responded_by' => $user->id,
            'response_note' => $note,
            'seen_at' => $this->seen_at ?? now(),
        ])->save();
    }

    private function recordOnAssignable(string $suffix, ?string $note): void
    {
        $assignable = $this->assignable;

        if (! $assignable instanceof TracksActivity) {
            return;
        }

        $employee = app(EmployeeRecipients::class)->names([(int) $this->employee_id])[(int) $this->employee_id] ?? null;

        $assignable->recordActivity($assignable->getMorphClass().'.'.$suffix, properties: [
            ...($employee === null ? [] : ['employee' => $employee]),
            'assignment_id' => $this->id,
            'assigned_by' => $this->assigned_by,
            'employee_ids' => [(int) $this->employee_id],
            ...($note === null ? [] : ['note' => $note]),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'seen_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
