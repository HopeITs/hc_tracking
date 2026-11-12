<?php

use App\Actions\AnnualPlans\SyncActivitySchedules;
use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\ActivityPlanItemCity;
use App\Models\ActivitySchedule;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\PlanCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2027-01-01', 'planned_date_end' => '2027-12-31']);
    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');
    $this->aleppoCentre = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => $this->aleppo]);
    $this->homsCentre = Project::factory()->create(['program_id' => $this->program->id, 'city_id' => $this->homs]);
    DB::table('project_proposal')->insert([
        ['project_id' => $this->aleppoCentre->id, 'proposal_id' => $this->proposal->id],
        ['project_id' => $this->homsCentre->id, 'proposal_id' => $this->proposal->id],
    ]);
    $this->plan = ActivityPlan::factory()->approved()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
});

/**
 * A planned activity of the plan in the given cities, with batches [start Monday, end Monday].
 *
 * @param  list<int>  $cityIds
 * @param  list<array{0: string, 1: string}>  $batches
 */
function plannedActivity(ActivityPlan $plan, array $cityIds, array $batches, bool $singleWeek = false): ActivityPlanItem
{
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $plan->proposal_id, 'program_id' => $plan->program_id]);
    $item = ActivityPlanItem::factory()->create([
        'activity_plan_id' => $plan->id,
        'result_id' => Result::factory()->activity($output)->create()->id,
        'is_single_week' => $singleWeek,
    ]);

    foreach ($cityIds as $cityId) {
        ActivityPlanItemCity::query()->create(['activity_plan_item_id' => $item->id, 'city_id' => $cityId]);
    }

    foreach ($batches as $sort => [$start, $end]) {
        ActivityPlanBatch::factory()->create(['activity_plan_item_id' => $item->id, 'start_week' => $start, 'end_week' => $end, 'sort' => $sort]);
    }

    return $item->fresh(['batches', 'cities']);
}

/**
 * @return array<string, list<int>|null>
 */
function calendarRows(int $resultId, ?int $projectId): array
{
    return DB::table('activity_schedules')->where('result_id', $resultId)->where('project_id', $projectId)->orderBy('month')->get()
        ->mapWithKeys(fn (object $row): array => [substr((string) $row->month, 0, 7) => $row->weeks === null ? null : json_decode($row->weeks, true)])
        ->all();
}

test('the plan runs on Mondays from the week the proposal starts in', function () {
    $calendar = PlanCalendar::forProposal($this->proposal);

    expect($calendar->firstMonday()->toDateString())->toBe('2026-12-28')
        ->and($calendar->lastMonday()->toDateString())->toBe('2027-12-27')
        ->and($calendar->weekCount())->toBe(53)
        ->and(count($calendar->mondaysOf(CarbonImmutable::parse('2027-03-01'))))->toBe(5);
});

test('a monday is numbered by its place among its month\'s mondays', function () {
    expect(PlanCalendar::weekOfMonth(CarbonImmutable::parse('2027-03-01')))->toBe(1)
        ->and(PlanCalendar::weekOfMonth(CarbonImmutable::parse('2027-03-08')))->toBe(2)
        ->and(PlanCalendar::weekOfMonth(CarbonImmutable::parse('2027-03-29')))->toBe(5);
});

test('an update falls due on the 3rd working day after the month, friday and saturday off', function () {
    expect(PlanCalendar::dueDateOf(CarbonImmutable::parse('2027-01-01'))->toDateString())->toBe('2027-02-03')
        ->and(PlanCalendar::dueDateOf(CarbonImmutable::parse('2027-03-01'))->toDateString())->toBe('2027-04-05');
});

test('every third month counted from the plan start closes a quarter', function () {
    $calendar = new PlanCalendar(CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2027-11-30'));

    expect($calendar->isQuarterEnd(CarbonImmutable::parse('2027-04-01')))->toBeTrue()
        ->and($calendar->isQuarterEnd(CarbonImmutable::parse('2027-07-01')))->toBeTrue()
        ->and($calendar->isQuarterEnd(CarbonImmutable::parse('2027-03-01')))->toBeFalse()
        ->and($calendar->isQuarterEnd(CarbonImmutable::parse('2027-06-01')))->toBeFalse();
});

test('batches become months with week numbers, or the whole month when every monday is planned', function () {
    $item = plannedActivity($this->plan, [$this->aleppo, $this->homs], [['2027-03-01', '2027-04-12'], ['2027-06-14', '2027-06-14']]);

    expect(SyncActivitySchedules::calendarOf($item))->toBe([
        '2027-03' => null,
        '2027-04' => [1, 2],
        '2027-06' => [2],
    ]);
});

test('an activity in every governorate writes the proposal calendar the monthly report reads', function () {
    $item = plannedActivity($this->plan, [$this->aleppo, $this->homs], [['2027-03-01', '2027-04-12']]);
    ActivitySchedule::factory()->create(['result_id' => $item->result_id, 'month' => '2026-01-01']);

    app(SyncActivitySchedules::class)->handle($this->plan);

    expect(calendarRows($item->result_id, null))->toBe(['2027-03' => null, '2027-04' => [1, 2]])
        ->and(calendarRows($item->result_id, $this->aleppoCentre->id))->toBe([])
        ->and(ActivitySchedule::effectiveFor($item->result_id, $this->homsCentre->id))->toBe(['2027-03' => null, '2027-04' => [1, 2]]);
});

test('an activity in some governorates writes only their centres, so other centres get no month', function () {
    $item = plannedActivity($this->plan, [$this->aleppo], [['2027-05-03', '2027-05-10']]);

    app(SyncActivitySchedules::class)->handle($this->plan);

    expect(calendarRows($item->result_id, null))->toBe([])
        ->and(ActivitySchedule::effectiveFor($item->result_id, $this->aleppoCentre->id))->toBe(['2027-05' => [1, 2]])
        ->and(ActivitySchedule::effectiveFor($item->result_id, $this->homsCentre->id))->toBe([]);
});
