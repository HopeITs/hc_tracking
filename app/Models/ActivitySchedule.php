<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled month of a framework activity (contracts §11.2, D-49).
 *
 * A row with no `project_id` belongs to the proposal calendar; a row with one
 * is that center's override, which replaces the proposal calendar for that
 * project as soon as it holds any month. `weeks` lists Monday-start weeks
 * 1–5; null is the whole month.
 *
 * Written only by SaveActivitySchedule and ShiftActivitySchedulesOnApproval.
 * A calendar is passed around as `array<'Y-m', list<int>|null>`, months
 * ascending.
 *
 * @property int $id
 * @property int $result_id
 * @property int|null $project_id
 * @property CarbonImmutable $month
 * @property list<int>|null $weeks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['result_id', 'project_id', 'month', 'weeks'])]
class ActivitySchedule extends Model
{
    /** @use HasFactory<ActivityScheduleFactory> */
    use HasFactory;

    /**
     * The activity this month belongs to.
     *
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * The center whose own calendar this row is part of; null for the
     * proposal calendar.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The calendar an activity follows in one project: the project's own
     * override when it has one, else the proposal calendar. The one rule T17
     * reads ("an activity with no month never reaches the report").
     *
     * @return array<string, list<int>|null>
     */
    public static function effectiveFor(int $activityId, int $projectId): array
    {
        $rows = self::query()
            ->select(['id', 'result_id', 'project_id', 'month', 'weeks'])
            ->where('result_id', $activityId)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $projectId))
            ->get();

        $override = $rows->where('project_id', $projectId);

        return self::calendarOf($override->isNotEmpty() ? $override : $rows->whereNull('project_id'));
    }

    /**
     * Rows of one scope as a calendar, months ascending.
     *
     * @param  iterable<self>  $rows
     * @return array<string, list<int>|null>
     */
    public static function calendarOf(iterable $rows): array
    {
        $calendar = [];

        foreach ($rows as $row) {
            $calendar[$row->month->format('Y-m')] = $row->weeks;
        }

        ksort($calendar);

        return $calendar;
    }

    /**
     * A short fingerprint of a calendar, so an editor can prove it saw the
     * stored one before changing it.
     *
     * @param  array<string, list<int>|null>  $calendar
     */
    public static function versionOf(array $calendar): string
    {
        ksort($calendar);

        return substr(sha1((string) json_encode($calendar)), 0, 16);
    }

    /**
     * The calendar as one readable line for the activity log, e.g.
     * "2026-03 (1, 3), 2026-04"; null when empty.
     *
     * @param  array<string, list<int>|null>  $calendar
     */
    public static function summaryOf(array $calendar): ?string
    {
        if ($calendar === []) {
            return null;
        }

        $parts = [];

        foreach ($calendar as $month => $weeks) {
            $parts[] = $weeks === null ? $month : $month.' ('.implode(', ', $weeks).')';
        }

        return implode(', ', $parts);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'weeks' => 'array',
        ];
    }
}
