<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\AssignmentStatus;
use App\Models\Activity;
use App\Models\Assignment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * A model with fields that put an HR employee in charge (a project's owner).
 * Setting or changing one asks that person to acknowledge or reject it (see
 * App\Models\Assignment).
 *
 * Seeders, imports and the HR mirror run inside Activity::withoutRecording(),
 * and so create no assignments either: nobody is asked about bulk loads.
 */
trait HasAssignments
{
    /**
     * Listens to created / updated rather than saved so the assignment
     * exists before RecordsActivity fans the change out: the notification
     * offers acknowledge / reject only while one is pending. Trait boot
     * order is the order of the `use` list, so list this trait before
     * RecordsActivity.
     */
    public static function bootHasAssignments(): void
    {
        $sync = function (self $model): void {
            if (! Activity::isRecording()) {
                return;
            }

            foreach ($model->assignmentFields() as $field) {
                if ($model->wasRecentlyCreated || $model->wasChanged($field)) {
                    $model->syncAssignment($field);
                }
            }
        };

        static::created($sync);
        static::updated($sync);
    }

    /**
     * Columns holding the HR employee id of an assignee.
     *
     * @return list<string>
     */
    abstract public function assignmentFields(): array;

    /**
     * @return MorphMany<Assignment, $this>
     */
    public function assignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'assignable');
    }

    /**
     * The assignment that stands for a field right now: the latest one that
     * has not been superseded.
     */
    public function currentAssignment(string $field): ?Assignment
    {
        return $this->assignments()->current()->where('field', $field)->latest('id')->first();
    }

    /**
     * Whether the person in a field has acknowledged it. A field nobody
     * fills has nothing to wait for.
     */
    public function isAssignmentAcknowledged(string $field): bool
    {
        if ($this->getAttribute($field) === null) {
            return true;
        }

        return $this->currentAssignment($field)?->status === AssignmentStatus::Acknowledged;
    }

    /**
     * Close what stood for a field and ask the new person, unless they put
     * themselves there, which needs no one's agreement.
     */
    protected function syncAssignment(string $field): void
    {
        $this->assignments()->current()->where('field', $field)->get()->each->supersede();

        $employeeId = $this->getAttribute($field);

        if ($employeeId === null) {
            return;
        }

        $actor = Auth::user();
        $selfAssigned = $actor !== null && (int) $actor->employee_id === (int) $employeeId;

        $this->assignments()->create([
            'field' => $field,
            'employee_id' => (int) $employeeId,
            'assigned_by' => $actor?->getAuthIdentifier(),
            'status' => $selfAssigned ? AssignmentStatus::Acknowledged : AssignmentStatus::Pending,
            'responded_at' => $selfAssigned ? now() : null,
            'responded_by' => $selfAssigned ? $actor->getAuthIdentifier() : null,
        ]);

        if ($selfAssigned) {
            $this->assignmentAcknowledged($field);
        }
    }

    /**
     * Called when the person in a field acknowledged it, including by putting
     * themselves there. A model reacts by overriding it (a project approves
     * itself once its owner has acknowledged).
     */
    public function assignmentAcknowledged(string $field): void {}
}
