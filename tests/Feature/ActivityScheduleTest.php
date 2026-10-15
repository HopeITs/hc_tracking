<?php

use App\Actions\Results\SaveActivitySchedule;
use App\Actions\Results\SaveResult;
use App\Enums\ActivityKind;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Events\ProposalStatusChanged;
use App\Listeners\ShiftActivitySchedulesOnApproval;
use App\Models\ActivitySchedule;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    $this->project = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $this->proposal->id]);
    $this->output = scheduleOutput($this->proposal, $this->program);
    $this->activity = Result::factory()->activity($this->output)->create();
    $this->meal = User::factory()->role('MEAL')->create();
});

/**
 * An output in the proposal's framework, under an impact and an outcome.
 */
function scheduleOutput(Proposal $proposal, Program $program): Result
{
    $impact = Result::factory()->type(ResultType::Impact)->create(['proposal_id' => $proposal->id, 'program_id' => $program->id]);
    $outcome = Result::factory()->type(ResultType::Outcome)->childOf($impact)->create(['proposal_id' => $proposal->id, 'program_id' => $program->id]);

    return Result::factory()->type(ResultType::Output)->childOf($outcome)->create(['proposal_id' => $proposal->id, 'program_id' => $program->id]);
}

/**
 * The stored calendar of one scope, read straight from the table: month => weeks (null = whole month).
 *
 * @return array<string, list<int>|null>
 */
function storedSchedule(Result $activity, ?int $projectId = null): array
{
    return DB::table('activity_schedules')
        ->where('result_id', $activity->id)
        ->where('project_id', $projectId)
        ->orderBy('month')
        ->get()
        ->mapWithKeys(fn (object $row): array => [substr((string) $row->month, 0, 7) => $row->weeks === null ? null : json_decode($row->weeks, true)])
        ->all();
}

/**
 * Save a calendar as the given user, against the version currently stored.
 *
 * @param  list<string>  $months
 * @param  array<string, list<int>>  $weeks
 * @return array<string, list<int>|null>
 */
function saveSchedule(User $user, Proposal $proposal, Result $activity, array $months, array $weeks = [], ?int $projectId = null, ?string $version = null): array
{
    test()->actingAs($user);

    return app(SaveActivitySchedule::class)->handle($proposal, $activity->id, $projectId, [
        'months' => $months,
        'weeks' => $weeks,
        'expected_version' => $version ?? ActivitySchedule::versionOf(storedSchedule($activity, $projectId)),
    ]);
}

/**
 * Move the proposal to approved with the given actual dates, as T08 will.
 */
function approveForSchedule(Proposal $proposal, string $begin, string $end): void
{
    DB::table('proposals')->where('id', $proposal->id)->update([
        'status' => ProposalStatus::Approved->value,
        'actual_date_begin' => $begin,
        'actual_date_end' => $end,
    ]);
    $proposal->refresh();
}

function activityCalendar(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('proposals.activity-calendar', ['proposalId' => $proposal->id]);
}

/**
 * @return array<string, list<string>>
 */
function scheduleErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

test('a continuous activity is scheduled every month from its start to its end', function () {
    $saved = saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03', '2026-06']);

    expect($saved)->toBe(['2026-03' => null, '2026-04' => null, '2026-05' => null, '2026-06' => null])
        ->and(storedSchedule($this->activity))->toBe($saved);
});

test('an event keeps its separate months and the weeks chosen for them', function () {
    $event = Result::factory()->activity($this->output)->create(['activity_kind' => ActivityKind::Event]);

    saveSchedule($this->meal, $this->proposal, $event, ['2026-09', '2026-02', '2026-05'], ['2026-05' => [4, 2, 2]]);

    expect(storedSchedule($event))->toBe(['2026-02' => null, '2026-05' => [2, 4], '2026-09' => null]);
});

test('a month outside the proposal period is refused and nothing is written', function () {
    $errors = scheduleErrors(fn () => saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-11', '2027-01']));

    expect($errors['months'])->toBe([__('results_framework.month_outside_period')])
        ->and(storedSchedule($this->activity))->toBe([]);
});

test('once approved, the months follow the actual period', function () {
    approveForSchedule($this->proposal, '2027-01-01', '2027-12-31');

    saveSchedule($this->meal, $this->proposal, $this->activity, ['2027-06']);

    expect(storedSchedule($this->activity))->toBe(['2027-06' => null])
        ->and(scheduleErrors(fn () => saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-06']))['months'])
        ->toBe([__('results_framework.month_outside_period')]);
});

test('a week number outside 1 to 5 is refused', function () {
    $errors = scheduleErrors(fn () => saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03'], ['2026-03' => [1, 6]]));

    expect($errors['weeks'])->toBe([__('results_framework.week_range')])
        ->and(storedSchedule($this->activity))->toBe([]);
});

test('weeks for a month that is not scheduled are refused', function () {
    $errors = scheduleErrors(fn () => saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03'], ['2026-04' => [2]]));

    expect($errors['weeks'])->toBe([__('results_framework.weeks_need_month')]);
});

test('a calendar changed by someone else is refused and nothing is written', function () {
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03']);
    $staleVersion = ActivitySchedule::versionOf([]);

    $errors = scheduleErrors(fn () => saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-08'], version: $staleVersion));

    expect($errors['schedule'])->toBe([__('results_framework.schedule_stale')])
        ->and(storedSchedule($this->activity))->toBe(['2026-03' => null]);
});

test('a center overrides the proposal calendar and can go back to it', function () {
    $otherProject = Project::factory()->create(['program_id' => $this->program->id]);
    DB::table('project_proposal')->insert(['project_id' => $otherProject->id, 'proposal_id' => $this->proposal->id]);

    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03', '2026-04']);
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-06'], ['2026-06' => [3]], $this->project->id);

    expect(ActivitySchedule::effectiveFor($this->activity->id, $this->project->id))->toBe(['2026-06' => [3]])
        ->and(ActivitySchedule::effectiveFor($this->activity->id, $otherProject->id))->toBe(['2026-03' => null, '2026-04' => null])
        ->and(storedSchedule($this->activity))->toBe(['2026-03' => null, '2026-04' => null]);

    saveSchedule($this->meal, $this->proposal, $this->activity, [], projectId: $this->project->id);

    expect(storedSchedule($this->activity, $this->project->id))->toBe([])
        ->and(ActivitySchedule::effectiveFor($this->activity->id, $this->project->id))->toBe(['2026-03' => null, '2026-04' => null]);
});

test('a project the proposal does not fund is refused', function () {
    $foreignProject = Project::factory()->create(['program_id' => $this->program->id]);

    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03'], projectId: $foreignProject->id);
})->throws(ModelNotFoundException::class);

test('an activity of another proposal is refused', function () {
    $other = Proposal::factory()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31']);
    $foreignActivity = Result::factory()->activity(scheduleOutput($other, $this->program))->create();

    saveSchedule($this->meal, $this->proposal, $foreignActivity, ['2026-03']);
})->throws(ModelNotFoundException::class);

test('a node that is not an activity has no calendar', function () {
    saveSchedule($this->meal, $this->proposal, $this->output, ['2026-03']);
})->throws(ModelNotFoundException::class);

test('a user who neither edits the proposal nor holds MEAL rights is refused', function () {
    saveSchedule(User::factory()->role('Managers')->create(), $this->proposal, $this->activity, ['2026-03']);
})->throws(AuthorizationException::class);

test('the proposal editor schedules before approval, only MEAL after it, and only that is logged', function () {
    $editor = User::factory()->role('Financial')->create();

    saveSchedule($editor, $this->proposal, $this->activity, ['2026-03']);

    expect(storedSchedule($this->activity))->toBe(['2026-03' => null])
        ->and(DB::table('activities')->where('subject_type', 'result')->where('subject_id', $this->activity->id)->where('event', 'result.updated')->count())->toBe(0);

    approveForSchedule($this->proposal, '2026-01-01', '2026-12-31');

    expect(fn () => saveSchedule($editor, $this->proposal, $this->activity, ['2026-04']))->toThrow(AuthorizationException::class);

    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03', '2026-04']);

    $logged = DB::table('activities')->where('subject_type', 'result')->where('subject_id', $this->activity->id)->where('event', 'result.updated')->sole();

    expect(storedSchedule($this->activity))->toBe(['2026-03' => null, '2026-04' => null])
        ->and($logged->actor_id)->toBe($this->meal->id)
        ->and(json_decode($logged->properties, true)['field'])->toBe('schedule')
        ->and(json_decode($logged->changes, true))->toEqual(['schedule' => ['2026-03', '2026-03, 2026-04']]);
});

test('approval shifts the calendar by the start delay once, keeping the weeks', function () {
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03', '2026-04'], ['2026-03' => [1, 3]]);
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-05'], ['2026-05' => [2]], $this->project->id);
    approveForSchedule($this->proposal, '2026-03-10', '2027-02-28');

    $event = new ProposalStatusChanged($this->proposal->id, ProposalStatus::Submitted, ProposalStatus::Approved, $this->meal->id);
    event($event);
    app(ShiftActivitySchedulesOnApproval::class)->handle($event);

    expect(new ShiftActivitySchedulesOnApproval)->toBeInstanceOf(ShouldQueue::class)
        ->and(storedSchedule($this->activity))->toBe(['2026-05' => [1, 3], '2026-06' => null])
        ->and(storedSchedule($this->activity, $this->project->id))->toBe(['2026-07' => [2]])
        ->and(DB::table('activity_schedule_shifts')->where('proposal_id', $this->proposal->id)->sole())
        ->delta_months->toBe(2)
        ->shifted->toBe(3);
});

test('an earlier actual start shifts the calendar back', function () {
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03']);
    approveForSchedule($this->proposal, '2025-11-20', '2026-10-31');

    event(new ProposalStatusChanged($this->proposal->id, ProposalStatus::Submitted, ProposalStatus::Approved, null));

    expect(storedSchedule($this->activity))->toBe(['2026-01' => null]);
});

test('no shift happens for another move or without an actual start', function () {
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03']);

    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => ProposalStatus::Approved->value]);
    event(new ProposalStatusChanged($this->proposal->id, ProposalStatus::Submitted, ProposalStatus::Approved, null));

    approveForSchedule($this->proposal, '2026-05-01', '2027-04-30');
    event(new ProposalStatusChanged($this->proposal->id, ProposalStatus::Approved, ProposalStatus::Active, null));

    expect(storedSchedule($this->activity))->toBe(['2026-03' => null])
        ->and(DB::table('activity_schedule_shifts')->count())->toBe(0);
});

test('an activity defaults to continuous and other levels carry no kind', function () {
    $this->actingAs($this->meal);
    $save = app(SaveResult::class);

    $activity = $save->handle(null, ['statement' => 'Run four sessions.', 'type' => 'activity', 'checkout_period' => 'quarterly', 'parent_id' => $this->output->id], $this->proposal);
    $event = $save->handle(null, ['statement' => 'Hold a launch day.', 'type' => 'activity', 'activity_kind' => 'event', 'checkout_period' => 'quarterly', 'parent_id' => $this->output->id], $this->proposal);
    $output = $save->handle(null, ['statement' => 'Farmers trained.', 'type' => 'output', 'activity_kind' => 'event', 'checkout_period' => 'quarterly', 'parent_id' => $this->output->parent_id], $this->proposal);

    expect(DB::table('results')->where('id', $activity->id)->value('activity_kind'))->toBe('continuous')
        ->and(DB::table('results')->where('id', $event->id)->value('activity_kind'))->toBe('event')
        ->and(DB::table('results')->where('id', $output->id)->value('activity_kind'))->toBeNull()
        ->and(DB::table('results')->where('id', $this->activity->id)->value('activity_kind'))->toBe('continuous');
});

test('the result form saves the activity kind and shows the field for activities only', function () {
    Livewire::actingAs($this->meal)
        ->test('proposals.logframe', ['proposalId' => $this->proposal->id])
        ->call('openCreateModal', $this->output->id)
        ->assertSeeHtml('wire:model="activityKind"')
        ->set('statement', 'Hold a launch day.')
        ->set('activityKind', 'event')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->call('openCreateModal', $this->output->parent_id)
        ->assertDontSeeHtml('wire:model="activityKind"');

    expect(DB::table('results')->where('statement', 'Hold a launch day.')->value('activity_kind'))->toBe('event');
});

test('the kind of an activity that has months cannot change', function () {
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03']);

    $errors = scheduleErrors(fn () => app(SaveResult::class)->handle($this->activity, [
        'statement' => $this->activity->statement,
        'type' => 'activity',
        'activity_kind' => 'event',
        'checkout_period' => 'quarterly',
        'parent_id' => $this->output->id,
    ], $this->proposal));

    expect($errors['activity_kind'])->toBe([__('results_framework.activity_kind_scheduled')])
        ->and(DB::table('results')->where('id', $this->activity->id)->value('activity_kind'))->toBe('continuous');
});

test('the grid toggles months, sets weeks and removes a month', function () {
    $event = Result::factory()->activity($this->output)->create(['activity_kind' => ActivityKind::Event]);

    activityCalendar($this->meal, $this->proposal)
        ->call('toggleMonth', $event->id, '2026-03', ActivitySchedule::versionOf([]))
        ->assertHasNoErrors()
        ->call('saveWeeks', $event->id, '2026-03', [2, 3], ActivitySchedule::versionOf(['2026-03' => null]))
        ->assertHasNoErrors()
        ->call('toggleMonth', $event->id, '2026-05', ActivitySchedule::versionOf(['2026-03' => [2, 3]]))
        ->call('removeMonth', $event->id, '2026-03', ActivitySchedule::versionOf(['2026-03' => [2, 3], '2026-05' => null]))
        ->assertHasNoErrors();

    expect(storedSchedule($event))->toBe(['2026-05' => null]);
});

test('the grid shows the no-calendar badge and disables months outside the period', function () {
    $scheduled = Result::factory()->activity($this->output)->create();
    saveSchedule($this->meal, $this->proposal, $scheduled, ['2026-02']);

    activityCalendar($this->meal, $this->proposal)
        ->assertSeeHtml('data-test="no-calendar-'.$this->activity->id.'"')
        ->assertDontSeeHtml('data-test="no-calendar-'.$scheduled->id.'"')
        ->assertSee(__('results_framework.no_calendar'))
        ->assertSeeHtml('data-test="month-cell-'.$scheduled->id.'-2026-02" data-scheduled="true"');
});

test('the grid is read-only for the proposal editor after approval', function () {
    $editor = User::factory()->role('Financial')->create();
    approveForSchedule($this->proposal, '2026-01-01', '2026-12-31');

    activityCalendar($editor, $this->proposal)
        ->assertSee(__('results_framework.calendar_read_only_approved'));

    activityCalendar($editor, $this->proposal)
        ->call('toggleMonth', $this->activity->id, '2026-03', ActivitySchedule::versionOf([]))
        ->assertForbidden();

    expect(storedSchedule($this->activity))->toBe([]);
});

test('the grid resets a center calendar, and the proposal editor cannot after approval', function () {
    $editor = User::factory()->role('Financial')->create();
    $centerVersion = ActivitySchedule::versionOf(['2026-06' => null]);
    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-06'], projectId: $this->project->id);
    approveForSchedule($this->proposal, '2026-01-01', '2026-12-31');

    activityCalendar($editor, $this->proposal)
        ->set('scope', (string) $this->project->id)
        ->call('confirmReset', $this->activity->id, $centerVersion)
        ->call('resetCenter')
        ->assertForbidden();

    expect(storedSchedule($this->activity, $this->project->id))->toBe(['2026-06' => null]);

    activityCalendar($this->meal, $this->proposal)
        ->set('scope', (string) $this->project->id)
        ->call('confirmReset', $this->activity->id, $centerVersion)
        ->call('resetCenter')
        ->assertHasNoErrors()
        ->assertSet('showResetModal', false);

    expect(storedSchedule($this->activity, $this->project->id))->toBe([]);
});

test('the grid holds a flat query count whatever the number of activities', function () {
    $countQueries = function (): int {
        activityCalendar($this->meal, $this->proposal);
        DB::flushQueryLog();
        DB::enableQueryLog();

        activityCalendar($this->meal, $this->proposal);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    };

    saveSchedule($this->meal, $this->proposal, $this->activity, ['2026-03']);
    $small = $countQueries();

    foreach (range(1, 27) as $index) {
        $activity = Result::factory()->activity($this->output)->create();
        saveSchedule($this->meal, $this->proposal, $activity, ['2026-04'], projectId: $this->project->id);
    }

    expect($countQueries())->toBe($small);
});
