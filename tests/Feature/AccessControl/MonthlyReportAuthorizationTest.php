<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportReopenRequest;
use App\Models\Project;
use App\Models\Supervision;
use App\Models\User;

/*
| MonthlyReportPolicy (contracts §13.1 items 6 and 9, D-09a, D-12a, D-20a).
| Preparer side = report writers who own the project or supervise its owner.
| Validator = the responsible MEAL officer, the MEAL Manager slot holder, or
| any MEAL role holder only when no one is responsible; never the preparer
| or submitter, never the General Manager. Reopen decider = the MEAL Manager
| slot holder, else active Public Relations Managers; never the requester.
*/

beforeEach(function () {
    $this->aleppo = insertHrCity('Aleppo');
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->project = Activity::withoutRecording(fn () => Project::factory()->active()->create([
        'city_id' => $this->aleppo, 'center_id' => null, 'user_id' => $this->ownerEmployee,
    ]));
    $this->owner = userWithRole('Public Relations', $this->ownerEmployee);
});

/**
 * A monthly report of the test project in the given state, prepared and
 * submitted by the owner where the state implies it.
 */
function monthlyReportIn(Project $project, MonthlyReportStatus $status, ?User $preparer = null): MonthlyReport
{
    $attributes = ['project_id' => $project->id, 'period' => '2026-10-01', 'prepared_by' => $preparer?->id];

    return Activity::withoutRecording(fn () => match ($status) {
        MonthlyReportStatus::Draft => MonthlyReport::factory()->create($attributes),
        MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted()->create([...$attributes, 'submitted_by' => $preparer?->id ?? User::factory()]),
        MonthlyReportStatus::Validated => MonthlyReport::factory()->validated()->create([...$attributes, 'submitted_by' => $preparer?->id ?? User::factory()]),
    });
}

/**
 * A MEAL user who is the governorate officer of the given city.
 */
function governorateOfficer(int $cityId): User
{
    $employeeId = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($cityId)->create(['employee_id' => $employeeId]));

    return userWithRole('MEAL', $employeeId);
}

test('report viewers see monthly reports; others do not', function () {
    $report = monthlyReportIn($this->project, MonthlyReportStatus::Draft);
    $roleless = Activity::withoutRecording(fn () => User::factory()->create());

    expect(userWithRole('Team Member')->can('viewAny', MonthlyReport::class))->toBeTrue()
        ->and(userWithRole('Team Member')->can('view', $report))->toBeTrue()
        ->and($roleless->can('view', $report))->toBeFalse();
});

test('the project owner opens, edits, submits and deletes a never-submitted draft', function () {
    $draft = monthlyReportIn($this->project, MonthlyReportStatus::Draft, $this->owner);

    expect($this->owner->can('open', [MonthlyReport::class, $this->project]))->toBeTrue()
        ->and($this->owner->can('update', $draft))->toBeTrue()
        ->and($this->owner->can('submit', $draft))->toBeTrue()
        ->and($this->owner->can('delete', $draft))->toBeTrue();
});

test('a report writer who neither owns nor supervises the project is refused', function () {
    $draft = monthlyReportIn($this->project, MonthlyReportStatus::Draft);
    $stranger = userWithRole('Public Relations', insertHrEmployee(firstNameEn: 'Sara', lastNameEn: 'Stranger'));

    expect($stranger->can('open', [MonthlyReport::class, $this->project]))->toBeFalse()
        ->and($stranger->can('update', $draft))->toBeFalse()
        ->and($stranger->can('submit', $draft))->toBeFalse();
});

test('a supervisor of the owner prepares on their behalf', function () {
    $draft = monthlyReportIn($this->project, MonthlyReportStatus::Draft);
    $supervisorEmployee = insertHrEmployee(firstNameEn: 'Hadi', lastNameEn: 'Supervisor');
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $supervisorEmployee, 'supervisee_employee_id' => $this->ownerEmployee,
    ]));
    $supervisor = userWithRole('Public Relations', $supervisorEmployee);

    expect($supervisor->can('open', [MonthlyReport::class, $this->project]))->toBeTrue()
        ->and($supervisor->can('update', $draft))->toBeTrue();
});

test('the owner without a report-writing permission is refused', function () {
    $viewer = userWithRole('Team Member', $this->ownerEmployee);

    expect($viewer->can('open', [MonthlyReport::class, $this->project]))->toBeFalse();
});

test('a submitted report is locked for the preparer and a returned one cannot be deleted', function () {
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);
    $returned = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->create([
        'period' => '2026-11-01', 'submitted_at' => '2026-11-30 10:00:00', 'submitted_by' => $this->owner->id,
        'returned_at' => '2026-12-02 10:00:00',
    ]));

    expect($this->owner->can('update', $submitted))->toBeFalse()
        ->and($this->owner->can('submit', $submitted))->toBeFalse()
        ->and($this->owner->can('delete', $submitted))->toBeFalse()
        ->and($this->owner->can('update', $returned))->toBeTrue()
        ->and($this->owner->can('delete', $returned))->toBeFalse();
});

test('the governorate officer validates, returns, fills MEAL lines and marks seen a submitted report', function () {
    $officer = governorateOfficer($this->aleppo);
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);
    $draft = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->create(['period' => '2026-11-01']));

    expect($officer->can('validate', $submitted))->toBeTrue()
        ->and($officer->can('return', $submitted))->toBeTrue()
        ->and($officer->can('fillMealLines', $submitted))->toBeTrue()
        ->and($officer->can('markSeen', $submitted))->toBeTrue()
        ->and($officer->can('validate', $draft))->toBeFalse()
        ->and($officer->can('fillMealLines', $draft))->toBeFalse();
});

test('the officer of another governorate cannot validate', function () {
    governorateOfficer($this->aleppo);
    $otherOfficer = governorateOfficer(insertHrCity('Idlib'));
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);

    expect($otherOfficer->can('validate', $submitted))->toBeFalse()
        ->and($otherOfficer->can('return', $submitted))->toBeFalse();
});

test('an officer from another governorate assigned to the project validates it', function () {
    $aleppoOfficer = governorateOfficer($this->aleppo);
    $idlibEmployee = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Idlib');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity(insertHrCity('Idlib'))->create(['employee_id' => $idlibEmployee]));
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($this->project->id)->create(['employee_id' => $idlibEmployee]));
    $projectOfficer = userWithRole('MEAL', $idlibEmployee);
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);

    expect($projectOfficer->can('validate', $submitted))->toBeTrue()
        ->and($aleppoOfficer->can('validate', $submitted))->toBeFalse();
});

test('the MEAL Manager slot holder validates even when an officer is responsible', function () {
    governorateOfficer($this->aleppo);
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);

    expect(mealManager()->can('validate', $submitted))->toBeTrue();
});

test('any MEAL role holder validates only when no one is responsible', function () {
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami', lastNameEn: 'Meal'));

    expect($meal->can('validate', $submitted))->toBeTrue();

    governorateOfficer($this->aleppo);

    expect($meal->can('validate', $submitted))->toBeFalse();
});

test('the preparer or submitter never validates their own report', function () {
    $officer = governorateOfficer($this->aleppo);
    $preparedByOfficer = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->submitted()->create([
        'period' => '2026-10-01', 'prepared_by' => $officer->id, 'submitted_by' => $this->owner->id,
    ]));
    $submittedByOfficer = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->submitted()->create([
        'period' => '2026-11-01', 'prepared_by' => $this->owner->id, 'submitted_by' => $officer->id,
    ]));

    expect($officer->can('validate', $preparedByOfficer))->toBeFalse()
        ->and($officer->can('validate', $submittedByOfficer))->toBeFalse()
        ->and($this->owner->can('validate', $preparedByOfficer))->toBeFalse();
});

test('the General Manager never validates, even as the responsible officer', function () {
    $gmEmployee = insertHrEmployee(firstNameEn: 'Ghada', lastNameEn: 'General');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($this->aleppo)->create(['employee_id' => $gmEmployee]));
    $generalManager = userWithRole('General Manager', $gmEmployee);
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);

    expect($generalManager->can('validate', $submitted))->toBeFalse()
        ->and($generalManager->can('return', $submitted))->toBeFalse()
        ->and($generalManager->can('fillMealLines', $submitted))->toBeFalse();
});

test('the preparer side requests a reopen of a validated report only', function () {
    $validated = monthlyReportIn($this->project, MonthlyReportStatus::Validated, $this->owner);
    $draft = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->create(['period' => '2026-11-01']));
    $stranger = userWithRole('Public Relations', insertHrEmployee(firstNameEn: 'Sara', lastNameEn: 'Stranger'));

    expect($this->owner->can('requestReopen', $validated))->toBeTrue()
        ->and($this->owner->can('requestReopen', $draft))->toBeFalse()
        ->and($stranger->can('requestReopen', $validated))->toBeFalse();
});

test('the MEAL Manager slot holder decides a reopen; Public Relations Managers only when the slot is vacant', function () {
    $validated = monthlyReportIn($this->project, MonthlyReportStatus::Validated, $this->owner);
    $request = Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create([
        'monthly_report_id' => $validated->id, 'requested_by' => $this->owner->id,
    ]));
    $prManager = userWithRole('Public Relations Manager', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Relations'));

    expect($prManager->can('decideReopen', [$validated, $request]))->toBeTrue();

    $slotHolder = mealManager();

    expect($slotHolder->can('decideReopen', [$validated, $request]))->toBeTrue()
        ->and($prManager->can('decideReopen', [$validated, $request]))->toBeFalse();
});

test('the requester, the preparer and the General Manager never decide a reopen', function () {
    $slotHolder = mealManager();
    $validated = monthlyReportIn($this->project, MonthlyReportStatus::Validated, $slotHolder);
    $request = Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create([
        'monthly_report_id' => $validated->id, 'requested_by' => $this->owner->id,
    ]));
    $ownRequest = Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create([
        'monthly_report_id' => $validated->id, 'requested_by' => $slotHolder->id,
    ]));

    expect($slotHolder->can('decideReopen', [$validated, $request]))->toBeFalse()
        ->and($slotHolder->can('decideReopen', [$validated, $ownRequest]))->toBeFalse()
        ->and(userWithRole('General Manager')->can('decideReopen', [$validated, $request]))->toBeFalse();
});

test('a decided request or a request of another report cannot be decided', function () {
    $slotHolder = mealManager();
    $validated = monthlyReportIn($this->project, MonthlyReportStatus::Validated, $this->owner);
    $other = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->validated()->create(['period' => '2026-11-01']));
    $denied = Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create([
        'monthly_report_id' => $validated->id, 'requested_by' => $this->owner->id, 'status' => 'denied',
    ]));
    $foreign = Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create([
        'monthly_report_id' => $other->id, 'requested_by' => $this->owner->id,
    ]));

    expect($slotHolder->can('decideReopen', [$validated, $denied]))->toBeFalse()
        ->and($slotHolder->can('decideReopen', [$validated, $foreign]))->toBeFalse();
});
