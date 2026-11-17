<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ScheduleBaselineItem;
use App\Models\ScheduleDependency;
use App\Models\Task;

/**
 * Removes a schedule item's Gantt links and baseline rows when the task
 * itself is deleted.
 *
 * The item columns on schedule_dependencies and schedule_baseline_items are
 * polymorphic, so no foreign key can cascade them. Like every observer here,
 * a raw query-builder delete bypasses this; delete through Eloquent.
 */
class ScheduleItemObserver
{
    public function deleted(Task $item): void
    {
        $type = 'task';

        ScheduleDependency::query()
            ->where(fn ($query) => $query->where('predecessor_type', $type)->where('predecessor_id', $item->id))
            ->orWhere(fn ($query) => $query->where('successor_type', $type)->where('successor_id', $item->id))
            ->delete();

        ScheduleBaselineItem::query()->where('item_type', $type)->where('item_id', $item->id)->delete();
    }
}
