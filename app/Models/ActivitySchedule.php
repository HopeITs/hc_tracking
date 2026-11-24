<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One scheduled month of a framework activity (contracts §11.2, D-49).
 *
 * A row with no `proposal_location_id` belongs to the proposal calendar; a
 * row with one is that location row's override, which replaces the proposal
 * calendar for that row as soon as it holds any month (Y-15, D-93). `weeks`
 * lists Monday-start weeks 1–5; null is the whole month. `project_id` is the
 * row's project, still written until Y-18 for readers not yet moved.
 *
 * Written only by SaveActivitySchedule, SyncActivitySchedules and
 * ShiftActivitySchedulesOnApproval. The override rule lives in
 * effectiveFor() / effectiveOf() and nowhere else.
 * A calendar is passed around as `array<'Y-m', list<int>|null>`, months
 * ascending.
 *
 * @property int $id
 * @property int $result_id
 * @property int|null $project_id
 * @property int|null $proposal_location_id
 * @property CarbonImmutable $month
 * @property list<int>|null $weeks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['result_id', 'project_id', 'proposal_location_id', 'month', 'weeks'])]
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
     * The project of the override's row; null for the proposal calendar.
     * Kept until Y-18 drops `project_id`.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The location row whose own calendar this row is part of; null for the
     * proposal calendar.
     *
     * @return BelongsTo<ProposalLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProposalLocation::class, 'proposal_location_id');
    }

    /**
     * The calendar an activity follows at one location row: the row's own
     * override when it has one, else the proposal calendar; the proposal
     * calendar when $locationId is null (contracts §11.2, D-49). The one rule
     * T17 reads ("an activity with no month never reaches the report").
     *
     * @return array<string, list<int>|null>
     */
    public static function effectiveFor(int $activityId, ?int $locationId): array
    {
        $rows = self::query()
            ->select(['id', 'result_id', 'proposal_location_id', 'month', 'weeks'])
            ->where('result_id', $activityId)
            ->where(fn ($query) => $locationId === null
                ? $query->whereNull('proposal_location_id')
                : $query->whereNull('proposal_location_id')->orWhere('proposal_location_id', $locationId))
            ->get();

        return self::effectiveOf($rows, $locationId);
    }

    /**
     * effectiveFor() on rows already loaded, for a page that renders many
     * activities and must not query per activity. $rows are one activity's
     * rows; rows of other locations are ignored.
     *
     * @param  Collection<int, self>  $rows
     * @return array<string, list<int>|null>
     */
    public static function effectiveOf(Collection $rows, ?int $locationId): array
    {
        $override = $locationId === null
            ? collect()
            : $rows->filter(fn (self $row): bool => $row->proposal_location_id === $locationId);

        return self::calendarOf($override->isNotEmpty()
            ? $override
            : $rows->filter(fn (self $row): bool => $row->proposal_location_id === null));
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
            'proposal_location_id' => 'integer',
            'weeks' => 'array',
        ];
    }
}
