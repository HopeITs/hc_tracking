<?php

use App\Actions\MonthlyReports\DecideMonthlyReportReopen;
use App\Actions\MonthlyReports\RequestMonthlyReportReopen;
use App\Actions\MonthlyReports\ReturnMonthlyReport;
use App\Actions\MonthlyReports\SaveMonthlyReportActivities;
use App\Actions\MonthlyReports\SubmitMonthlyReport;
use App\Actions\MonthlyReports\ValidateMonthlyReport;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\ActivitySchedule;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivityStatus;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\MonthlyReportActivities;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Activities this month (contracts §13, §13.1 item 8; D-49b, D-51): what the
| report lists, the inputs each status needs, and the project activity
| statuses written on Validate and restored on a granted reopen.
*/

beforeEach(function () {
    $this->travelTo('2026-12-02 10:00:00');
    $this->city = insertHrCity('Aleppo');
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->owner = userWithRole('Public Relations', $this->ownerEmployee);
    $this->officerEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($this->city)->create(['employee_id' => $this->officerEmployee]));
    $this->officer = userWithRole('MEAL', $this->officerEmployee);

    $this->project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'program_id' => Program::factory()->create()->id,
        'city_id' => $this->city,
        'center_id' => null,
        'user_id' => $this->ownerEmployee,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]));
    $this->proposal = Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create(['status' => ProposalStatus::Approved]));
    $this->project->proposals()->attach($this->proposal->id, ['target_beneficiaries' => 100]);
    $this->output = Activity::withoutRecording(fn (): Result => Result::factory()->type(ResultType::Output)->create(['proposal_id' => $this->proposal->id]));
});

function mraActivity(ActivityKind $kind = ActivityKind::Continuous, ?int $proposalId = null): Result
{
    return Activity::withoutRecording(function () use ($kind, $proposalId): Result {
        $output = $proposalId === null
            ? test()->output
            : Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposalId]);

        return Result::factory()->activity($output)->create(['activity_kind' => $kind]);
    });
}

/**
 * @param  list<int>|null  $weeks
 */
function mraSchedule(Result $activity, string $month, ?array $weeks = null, ?int $projectId = null): void
{
    ActivitySchedule::query()->create(['result_id' => $activity->id, 'project_id' => $projectId, 'month' => $month, 'weeks' => $weeks]);
}

function mraReport(string $month, MonthlyReportStatus $status = MonthlyReportStatus::Draft): MonthlyReport
{
    $attributes = ['project_id' => test()->project->id, 'period' => $month];

    return Activity::withoutRecording(fn (): MonthlyReport => match ($status) {
        MonthlyReportStatus::Draft => MonthlyReport::factory()->create($attributes),
        MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted()->create([...$attributes, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
        MonthlyReportStatus::Validated => MonthlyReport::factory()->validated()->create([...$attributes, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
    });
}

/**
 * @param  list<array<string, mixed>>  $entries
 */
function mraSave(MonthlyReport $report, array $entries): MonthlyReport
{
    test()->actingAs(test()->owner);

    return app(SaveMonthlyReportActivities::class)->handle($report->fresh(), MonthlyReportStatus::Draft, $entries);
}

/**
 * @return array<string, list<string>>
 */
function mraErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a validation error.');
}

/**
 * @return array<int, array<string, mixed>> keyed by activity id
 */
function mraRows(MonthlyReport $report): array
{
    return MonthlyReportActivities::for($report->fresh())->keyBy(fn (array $row): int => $row['activity']->id)->all();
}

it('lists the activities scheduled this month with their planned weeks', function () {
    $scheduled = mraActivity();
    mraSchedule($scheduled, '2026-12-01', [2, 3]);
    $wholeMonth = mraActivity();
    mraSchedule($wholeMonth, '2026-12-01');
    $otherMonth = mraActivity();
    mraSchedule($otherMonth, '2026-11-01');
    $otherProposal = mraActivity(proposalId: Activity::withoutRecording(fn (): int => Proposal::factory()->create(['status' => ProposalStatus::Approved])->id));
    mraSchedule($otherProposal, '2026-12-01');

    $rows = mraRows(mraReport('2026-12-01'));

    expect(array_keys($rows))->toEqualCanonicalizing([$scheduled->id, $wholeMonth->id])
        ->and($rows[$scheduled->id]['planned_weeks'])->toBe([2, 3])
        ->and($rows[$wholeMonth->id]['planned_weeks'])->toBeNull()
        ->and($rows[$scheduled->id]['carried_from'])->toBeNull();
});

it('uses the project override calendar instead of the proposal calendar', function () {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');
    mraSchedule($activity, '2026-11-01', projectId: test()->project->id);

    expect(mraRows(mraReport('2026-12-01')))->toBeEmpty()
        ->and(mraRows(mraReport('2026-11-01')))->toHaveKey($activity->id);
});

it('carries an unfinished activity over without a schedule row and stops a completed one', function () {
    $ongoing = mraActivity();
    $late = mraActivity();
    $completed = mraActivity();
    $october = mraReport('2026-10-01', MonthlyReportStatus::Validated);

    ProjectActivityStatus::query()->create(['project_id' => test()->project->id, 'result_id' => $ongoing->id, 'status' => ActivityStatus::Ongoing, 'monthly_report_id' => $october->id]);
    ProjectActivityStatus::query()->create(['project_id' => test()->project->id, 'result_id' => $late->id, 'status' => ActivityStatus::NotStarted, 'monthly_report_id' => $october->id]);
    ProjectActivityStatus::query()->create(['project_id' => test()->project->id, 'result_id' => $completed->id, 'status' => ActivityStatus::Completed, 'monthly_report_id' => $october->id]);
    mraSchedule($completed, '2026-12-01');

    $rows = mraRows(mraReport('2026-12-01'));

    expect(array_keys($rows))->toEqualCanonicalizing([$ongoing->id, $late->id])
        ->and($rows[$ongoing->id]['carried_from']->format('Y-m'))->toBe('2026-10')
        ->and($rows[$ongoing->id]['late'])->toBeFalse()
        ->and($rows[$late->id]['late'])->toBeTrue();
});

it('saves an entry per activity', function () {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');
    $report = mraReport('2026-12-01');

    mraSave($report, [['result_id' => $activity->id, 'status' => 'started', 'started_on' => '2026-12-01', 'note' => 'On track']]);
    mraSave($report, [['result_id' => $activity->id, 'status' => 'started', 'started_on' => '2026-12-02']]);

    $entry = MonthlyReportActivityEntry::query()->sole();
    expect($entry->status)->toBe(ActivityStatus::Started)
        ->and($entry->started_on->toDateString())->toBe('2026-12-02')
        ->and($entry->note)->toBeNull();
});

it('rejects the inputs each status needs', function (array $entry, string $field, string $key) {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');

    $errors = mraErrors(fn () => mraSave(mraReport('2026-12-01'), [['result_id' => $activity->id, ...$entry]]));

    expect($errors["entries.0.{$field}"])->toBe([__("monthly_reports.{$key}")]);
})->with([
    'not started without reason' => [['status' => 'not_started'], 'reason', 'activity_reason_min'],
    'not started, short reason' => [['status' => 'not_started', 'reason' => 'too short'], 'reason', 'activity_reason_min'],
    'started without date' => [['status' => 'started'], 'started_on', 'activity_date_required'],
    'suspended without reason' => [['status' => 'suspended'], 'reason', 'activity_reason_required'],
    'cancelled without reason' => [['status' => 'cancelled'], 'reason', 'activity_reason_required'],
    'completed without date' => [['status' => 'completed'], 'completed_on', 'activity_date_required'],
    'continuous conducted' => [['status' => 'conducted', 'executed_on' => '2026-12-01'], 'status', 'activity_status_not_allowed'],
]);

it('accepts a 10-character reason for not started', function () {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');

    mraSave(mraReport('2026-12-01'), [['result_id' => $activity->id, 'status' => 'not_started', 'reason' => 'No funding']]);

    expect(MonthlyReportActivityEntry::query()->sole()->status)->toBe(ActivityStatus::NotStarted);
});

it('lets an event be conducted but not ongoing', function () {
    $event = mraActivity(ActivityKind::Event);
    mraSchedule($event, '2026-12-01');
    $report = mraReport('2026-12-01');

    $errors = mraErrors(fn () => mraSave($report, [['result_id' => $event->id, 'status' => 'ongoing']]));
    mraSave($report, [['result_id' => $event->id, 'status' => 'conducted', 'executed_on' => '2026-12-01']]);

    expect($errors['entries.0.status'])->toBe([__('monthly_reports.activity_status_not_allowed')])
        ->and(MonthlyReportActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Conducted);
});

it('rejects the whole batch when one activity is not in the report', function () {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');
    $unlisted = mraActivity();

    expect(fn () => mraSave(mraReport('2026-12-01'), [
        ['result_id' => $activity->id, 'status' => 'started', 'started_on' => '2026-12-01'],
        ['result_id' => $unlisted->id, 'status' => 'started', 'started_on' => '2026-12-01'],
    ]))->toThrow(ModelNotFoundException::class);

    expect(MonthlyReportActivityEntry::query()->count())->toBe(0);
});

it('refuses a user outside the preparer side and a stale status', function () {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');
    $report = mraReport('2026-12-01');

    $this->actingAs($this->officer);
    expect(fn () => app(SaveMonthlyReportActivities::class)->handle($report, MonthlyReportStatus::Draft, []))->toThrow(AuthorizationException::class);

    $this->actingAs($this->owner);
    $errors = mraErrors(fn () => app(SaveMonthlyReportActivities::class)->handle($report, MonthlyReportStatus::Submitted, []));
    expect($errors['status'])->toBe([__('monthly_reports.stale')]);
});

it('blocks submit while a completed activity has no evidence', function () {
    $activity = mraActivity();
    mraSchedule($activity, '2026-12-01');
    $this->travelTo('2026-12-31 10:00:00');
    $report = mraReport('2026-12-01');
    mraSave($report, [['result_id' => $activity->id, 'status' => 'completed', 'completed_on' => '2026-12-20']]);

    $errors = mraErrors(fn () => app(SubmitMonthlyReport::class)->handle($report->fresh(), MonthlyReportStatus::Draft));

    expect($errors['activities'])->toBe([__('monthly_reports.activity_evidence_required')]);
});

it('writes the statuses on validate, none on return, and restores them on a granted reopen', function () {
    $slotHolder = mealManager();
    $activity = mraActivity();
    $october = mraReport('2026-10-01', MonthlyReportStatus::Validated);
    ProjectActivityStatus::query()->create(['project_id' => test()->project->id, 'result_id' => $activity->id, 'status' => ActivityStatus::NotStarted, 'monthly_report_id' => $october->id]);
    $fresh = mraActivity();
    mraSchedule($fresh, '2026-11-01');

    $november = mraReport('2026-11-01', MonthlyReportStatus::Submitted);
    MonthlyReportActivityEntry::query()->create(['monthly_report_id' => $november->id, 'result_id' => $activity->id, 'status' => ActivityStatus::Started, 'started_on' => '2026-11-03']);
    MonthlyReportActivityEntry::query()->create(['monthly_report_id' => $november->id, 'result_id' => $fresh->id, 'status' => ActivityStatus::Ongoing, 'started_on' => '2026-11-05']);

    $this->actingAs($this->officer);
    app(ReturnMonthlyReport::class)->handle($november, MonthlyReportStatus::Submitted, 'Please check the dates.');
    expect(ProjectActivityStatus::query()->where('result_id', $activity->id)->sole()->status)->toBe(ActivityStatus::NotStarted)
        ->and(ProjectActivityStatus::query()->where('result_id', $fresh->id)->exists())->toBeFalse();

    Activity::withoutRecording(fn () => $november->fresh()->forceFill(['status' => MonthlyReportStatus::Submitted])->save());
    app(ValidateMonthlyReport::class)->handle($november->fresh(), MonthlyReportStatus::Submitted);

    $started = ProjectActivityStatus::query()->where('result_id', $activity->id)->sole();
    expect($started->status)->toBe(ActivityStatus::Started)
        ->and($started->started_on->toDateString())->toBe('2026-11-03')
        ->and($started->monthly_report_id)->toBe($november->id)
        ->and(ProjectActivityStatus::query()->where('result_id', $fresh->id)->sole()->status)->toBe(ActivityStatus::Ongoing);

    $this->actingAs($this->owner);
    $request = app(RequestMonthlyReportReopen::class)->handle($november->fresh(), MonthlyReportStatus::Validated, 'A figure was entered twice.');
    $this->actingAs($slotHolder);
    app(DecideMonthlyReportReopen::class)->handle($november->fresh(), $request->id, MonthlyReportStatus::Validated, true);

    $restored = ProjectActivityStatus::query()->where('result_id', $activity->id)->sole();
    expect($restored->status)->toBe(ActivityStatus::NotStarted)
        ->and($restored->monthly_report_id)->toBe($october->id)
        ->and(ProjectActivityStatus::query()->where('result_id', $fresh->id)->exists())->toBeFalse();
});

it('reads the month\'s activities in a constant number of queries', function () {
    $count = function (int $activities): int {
        $report = mraReport(sprintf('2026-%02d-01', 10 + $activities % 3));

        foreach (range(1, $activities) as $ignored) {
            mraSchedule(mraActivity(), $report->period->toDateString(), [1]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        MonthlyReportActivities::for($report->fresh());
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(1))->toBe($count(5));
});
