<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Activity;
use App\Notifications\TracksActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * For child rows (assignees, attachments, files, notes, baselines, readings):
 * their creation or deletion is recorded as an activity on the parent record.
 */
trait RecordsParentActivity
{
    public static function bootRecordsParentActivity(): void
    {
        static::created(fn (Model $model) => $model->recordOnParent('created'));
        static::deleted(fn (Model $model) => $model->recordOnParent('deleted'));
    }

    /**
     * The record this row's activity is logged against.
     */
    abstract public function activityParent(): (Model&TracksActivity)|null;

    /**
     * The event a hook ("created" or "deleted") raises on the parent, or null
     * for none.
     */
    abstract protected function parentActivityEvent(string $hook): ?string;

    /**
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return [];
    }

    protected function recordOnParent(string $hook): void
    {
        if (! Activity::isRecording()) {
            return;
        }

        $event = $this->parentActivityEvent($hook);
        $parent = $event === null ? null : $this->activityParent();

        $parent?->recordActivity($event, properties: $this->parentActivityProperties($hook));
    }
}
