<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Activity;
use App\Notifications\ActivityRecorder;

/**
 * Records one Activity per created / meaningful update / deleted event of a
 * subject model (see App\Notifications\TracksActivity).
 *
 * activityEvents() lists the meaningful fields in priority order, each with
 * the event suffix a change to it raises; the first changed one names the
 * activity. Changes to any other field are still logged, as "{type}.updated",
 * but notify nobody.
 */
trait RecordsActivity
{
    /**
     * Properties captured while the row still existed, for the deleted event.
     *
     * @var array<string, mixed>
     */
    protected array $activityDeletionProperties = [];

    public static function bootRecordsActivity(): void
    {
        static::created(fn (self $model) => $model->recordLifecycleActivity('created'));
        static::updated(fn (self $model) => $model->recordLifecycleActivity('updated'));
        static::deleting(function (self $model): void {
            if (Activity::isRecording()) {
                $model->activityDeletionProperties = $model->activityPropertiesBeforeDelete();
            }
        });
        static::deleted(fn (self $model) => $model->recordLifecycleActivity('deleted'));
    }

    /**
     * Meaningful field => event suffix, in priority order.
     *
     * @return array<string, string>
     */
    abstract public function activityEvents(): array;

    /**
     * The event suffix a change to a meaningful field raises. Override to
     * split one field into several events; null skips the field.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        return $this->activityEvents()[$field] ?? null;
    }

    /**
     * Extra properties for an event (sentence params, snapshots).
     *
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        return [];
    }

    /**
     * Snapshot taken while the row still exists, stored on the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return [];
    }

    /**
     * The event recorded when the row is created.
     */
    protected function activityCreatedEvent(): string
    {
        return $this->getMorphClass().'.created';
    }

    /**
     * The event recorded when the row is deleted.
     */
    protected function activityDeletedEvent(): string
    {
        return $this->getMorphClass().'.deleted';
    }

    /**
     * Whether updates are logged at all (false for noisy bookkeeping models).
     */
    protected function activityRecordsUpdates(): bool
    {
        return true;
    }

    /**
     * Columns never written to the activity log, on top of the hidden ones:
     * values that must stay out of the log but are still serialized.
     *
     * @return list<string>
     */
    protected function activityExcludedFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return [];
    }

    /**
     * Record an activity no model event can see (a role sync, a pivot change).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $event, array $changes = [], array $properties = []): ?Activity
    {
        return app(ActivityRecorder::class)->record($this, $event, $changes, $properties);
    }

    /**
     * Read an attribute without tripping strict mode when a partial select
     * left it out.
     */
    protected function activityAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->getAttributes())) {
            return $this->getAttribute($key);
        }

        return $this->getKey() === null ? null : $this->newQueryWithoutScopes()->whereKey($this->getKey())->value($key);
    }

    protected function recordLifecycleActivity(string $hook): void
    {
        if (! Activity::isRecording()) {
            return;
        }

        $recorder = app(ActivityRecorder::class);

        if ($hook === 'created') {
            $event = $this->activityCreatedEvent();
            $recorder->record($this, $event, properties: $this->activityProperties($event));

            return;
        }

        if ($hook === 'deleted') {
            $event = $this->activityDeletedEvent();
            $recorder->record($this, $event, properties: [...$this->activityDeletionProperties, ...$this->activityProperties($event)]);

            return;
        }

        if (! $this->activityRecordsUpdates()) {
            return;
        }

        $changes = $this->activityChanges();

        if ($changes === []) {
            return;
        }

        [$field, $suffix] = [null, null];

        foreach (array_keys($this->activityEvents()) as $candidate) {
            if (! array_key_exists($candidate, $changes)) {
                continue;
            }

            $suffix = $this->activityEventForChange($candidate, $changes[$candidate][0], $changes[$candidate][1]);

            if ($suffix !== null) {
                $field = $candidate;

                break;
            }
        }

        $event = $this->getMorphClass().'.'.($suffix ?? 'updated');

        $recorder->record($this, $event, $changes, ['field' => $field, ...$this->activityProperties($event)], notify: $suffix !== null);
    }

    /**
     * Raw old/new values of every changed column, minus timestamps, hidden
     * columns and excluded fields (secrets never reach the log).
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function activityChanges(): array
    {
        $skipped = [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn(), ...$this->getHidden(), ...$this->activityExcludedFields()];
        $changes = [];

        foreach (array_keys($this->getChanges()) as $field) {
            if (in_array($field, $skipped, true)) {
                continue;
            }

            $changes[$field] = [$this->getRawOriginal($field), $this->getAttributes()[$field] ?? null];
        }

        return $changes;
    }
}
