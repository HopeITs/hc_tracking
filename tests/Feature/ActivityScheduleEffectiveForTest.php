<?php

declare(strict_types=1);

use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Result;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Y-15 slice 1 (D-49): `ActivitySchedule::effectiveFor()` is the only
 * implementation of the override rule. It must give exactly what the inline
 * copies it replaced gave, now keyed on the location row.
 */
beforeEach(function () {
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    $this->row = proposalRowForProject($this->proposal, Project::factory()->create(), false);
    $this->otherRow = proposalRowForProject($this->proposal, Project::factory()->create(), false);
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $this->proposal->id]);
    $this->activity = Result::factory()->activity($output)->create(['proposal_id' => $this->proposal->id]);
});

/**
 * One stored month, on the proposal calendar (null) or a row's.
 *
 * @param  list<int>|null  $weeks
 */
function effectiveMonth(Result $activity, ?ProposalLocation $row, string $month, ?array $weeks = null): void
{
    ActivitySchedule::factory()->create([
        'result_id' => $activity->id,
        'proposal_location_id' => $row?->id,
        'project_id' => $row?->project_id,
        'month' => $month.'-01',
        'weeks' => $weeks,
    ]);
}

/**
 * The rule as the four inline copies wrote it before Y-15, re-keyed on the
 * row: the location's own rows when it has any, else the proposal calendar.
 *
 * @return array<string, list<int>|null>
 */
function inlineRuleBeforeY15(int $activityId, ?int $locationId): array
{
    /** @var Collection<int, ActivitySchedule> $rows */
    $rows = ActivitySchedule::query()->where('result_id', $activityId)->get();
    $override = $locationId === null ? collect() : $rows->where('proposal_location_id', $locationId);

    return ActivitySchedule::calendarOf($override->isNotEmpty() ? $override : $rows->whereNull('proposal_location_id'));
}

test('with no override every location follows the proposal calendar', function () {
    effectiveMonth($this->activity, null, '2026-03');
    effectiveMonth($this->activity, null, '2026-04', [2]);

    foreach ([null, $this->row->id, $this->otherRow->id] as $locationId) {
        expect(ActivitySchedule::effectiveFor($this->activity->id, $locationId))
            ->toBe(inlineRuleBeforeY15($this->activity->id, $locationId))
            ->toBe(['2026-03' => null, '2026-04' => [2]]);
    }
});

test('a location with an override follows only its own months', function () {
    effectiveMonth($this->activity, null, '2026-03');
    effectiveMonth($this->activity, $this->row, '2026-06', [3]);

    expect(ActivitySchedule::effectiveFor($this->activity->id, $this->row->id))
        ->toBe(inlineRuleBeforeY15($this->activity->id, $this->row->id))
        ->toBe(['2026-06' => [3]])
        ->and(ActivitySchedule::effectiveFor($this->activity->id, null))->toBe(['2026-03' => null]);
});

test('another location\'s override changes nothing for this one', function () {
    effectiveMonth($this->activity, null, '2026-03');
    effectiveMonth($this->activity, $this->otherRow, '2026-08');

    expect(ActivitySchedule::effectiveFor($this->activity->id, $this->row->id))
        ->toBe(inlineRuleBeforeY15($this->activity->id, $this->row->id))
        ->toBe(['2026-03' => null])
        ->and(ActivitySchedule::effectiveFor($this->activity->id, $this->otherRow->id))->toBe(['2026-08' => null]);
});

test('the in-memory form gives the same answer from rows loaded once', function () {
    effectiveMonth($this->activity, null, '2026-03');
    effectiveMonth($this->activity, $this->row, '2026-06');

    $rows = ActivitySchedule::query()->where('result_id', $this->activity->id)->get();

    foreach ([null, $this->row->id, $this->otherRow->id] as $locationId) {
        expect(ActivitySchedule::effectiveOf($rows, $locationId))
            ->toBe(ActivitySchedule::effectiveFor($this->activity->id, $locationId));
    }
});

test('the calendar loads the overrides once for forty activities in a location scope', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $meal = mealManager();
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $this->proposal->id]);

    $countQueries = function () use ($meal): int {
        $render = fn () => Livewire::actingAs($meal)
            ->test('proposals.activity-calendar', ['proposalId' => $this->proposal->id])
            ->set('scope', (string) $this->row->id);

        $render();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    effectiveMonth($this->activity, $this->row, '2026-03');
    $small = $countQueries();

    foreach (range(1, 39) as $index) {
        $activity = Result::factory()->activity($output)->create(['proposal_id' => $this->proposal->id]);
        effectiveMonth($activity, $index % 2 === 0 ? $this->row : null, '2026-04');
    }

    expect($countQueries())->toBe($small);
});
