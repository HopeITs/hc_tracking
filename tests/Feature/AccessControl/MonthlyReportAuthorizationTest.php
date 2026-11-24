<?php

use App\Actions\MonthlyReports\OpenMonthlyReport;
use App\Actions\MonthlyReports\ValidateMonthlyReport;
use App\Actions\Reports\DeleteReportFile;
use App\Actions\Reports\SaveReportFile;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportReopenRequest;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportTemplate;
use App\Models\Supervision;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/*
| MonthlyReportPolicy (contracts §13.1 items 6 and 9, D-09a, D-12a, D-20a).
| Preparer side (D-84) = report writers who are the responsible of a location
| row of one of the template's proposals at its place, or supervise one. The
| legacy template factory gives the project's template a row whose responsible
| is the project manager (the owner here).
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
 * The project's (legacy) template, the one its reports are filed against.
 */
function mrauTemplate(Project $project): ReportTemplate
{
    return ReportTemplate::query()->where('project_id', $project->id)->orderBy('id')->first()
        ?? Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->create(['project_id' => $project->id]));
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

    expect($this->owner->can('open', [MonthlyReport::class, mrauTemplate($this->project)]))->toBeTrue()
        ->and($this->owner->can('update', $draft))->toBeTrue()
        ->and($this->owner->can('submit', $draft))->toBeTrue()
        ->and($this->owner->can('delete', $draft))->toBeTrue();
});

test('a report writer who neither owns nor supervises the project is refused', function () {
    $draft = monthlyReportIn($this->project, MonthlyReportStatus::Draft);
    $stranger = userWithRole('Public Relations', insertHrEmployee(firstNameEn: 'Sara', lastNameEn: 'Stranger'));

    expect($stranger->can('open', [MonthlyReport::class, mrauTemplate($this->project)]))->toBeFalse()
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

    expect($supervisor->can('open', [MonthlyReport::class, mrauTemplate($this->project)]))->toBeTrue()
        ->and($supervisor->can('update', $draft))->toBeTrue();
});

test('the owner without a report-writing permission is refused', function () {
    $viewer = userWithRole('Team Member', $this->ownerEmployee);

    expect($viewer->can('open', [MonthlyReport::class, mrauTemplate($this->project)]))->toBeFalse();
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
        ->and($generalManager->can('fillMealLines', $submitted))->toBeFalse()
        ->and($generalManager->can('markSeen', $submitted))->toBeFalse();
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

test('the generic Reports policy and file actions refuse a monthly report mirror', function () {
    $report = monthlyReportIn($this->project, MonthlyReportStatus::Draft, $this->owner);
    $mirror = fn (ReportStatus $status): Report => Activity::withoutRecording(fn () => Report::factory()->create([
        'monthly_report_id' => $report->id, 'user_id' => $this->owner->id, 'status' => $status,
    ]));
    $draft = $mirror(ReportStatus::Draft);
    $reviewed = $mirror(ReportStatus::Reviewed);
    $approver = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Approver'));
    $deleter = userWithRole('General Manager');
    $file = Activity::withoutRecording(fn () => ReportFile::factory()->create(['report_id' => $draft->id]));

    expect($this->owner->can('update', $draft))->toBeFalse()
        ->and($this->owner->can('submit', $draft))->toBeFalse()
        ->and($this->owner->can('delete', $draft))->toBeFalse()
        ->and($deleter->can('delete', $draft))->toBeFalse()
        ->and($approver->can('approve', $reviewed))->toBeFalse()
        ->and($approver->can('returnToDraft', $reviewed))->toBeFalse();

    $this->actingAs($this->owner);

    expect(fn () => app(SaveReportFile::class)->handle($draft, null, null, []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteReportFile::class)->handle($draft, $file))->toThrow(AuthorizationException::class);
});

test('the validator queue holds the officer\'s projects, every project for the slot holder, none for the General Manager or the preparer', function () {
    $officer = governorateOfficer($this->aleppo);
    $covered = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);
    $elsewhere = Activity::withoutRecording(fn () => MonthlyReport::factory()->submitted()->create([
        'project_id' => Project::factory()->active()->create(['city_id' => insertHrCity('Homs'), 'center_id' => null]),
        'period' => '2026-10-01',
    ]));
    $prepared = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($this->project)->submitted()->create([
        'period' => '2026-11-01', 'prepared_by' => $officer->id,
    ]));
    $slotHolder = mealManager();

    expect(MonthlyReport::query()->validatableBy($officer)->pluck('id')->all())->toBe([$covered->id])
        ->and(MonthlyReport::query()->validatableBy($slotHolder)->orderBy('id')->pluck('id')->all())->toBe([$covered->id, $elsewhere->id, $prepared->id])
        ->and(MonthlyReport::query()->validatableBy(userWithRole('General Manager'))->count())->toBe(0);
});

test('with nobody responsible any MEAL role holder sees the project in the queue', function () {
    $report = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal'));

    expect(MonthlyReport::query()->validatableBy($meal)->pluck('id')->all())->toBe([$report->id]);
});

test('a report on a template with no project and no center reaches the queue through its own project', function () {
    $template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->create([
        'project_id' => null, 'city_id' => $this->aleppo, 'program_id' => $this->project->program_id, 'center_id' => null,
    ]));
    $report = Activity::withoutRecording(fn (): MonthlyReport => MonthlyReport::factory()->submitted()->create([
        'report_template_id' => $template->id, 'project_id' => $this->project->id, 'period' => '2026-10-01',
    ]));
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal'));

    expect($template->place()->isUnset())->toBeTrue()
        ->and(MonthlyReport::query()->validatableBy($meal)->pluck('id')->all())->toBe([$report->id]);

    $officer = governorateOfficer($this->aleppo);
    $otherOfficer = governorateOfficer(insertHrCity('Homs'));

    expect(MonthlyReport::query()->validatableBy($officer)->pluck('id')->all())->toBe([$report->id])
        ->and(MonthlyReport::query()->validatableBy($otherOfficer)->count())->toBe(0)
        ->and(MonthlyReport::query()->validatableBy($meal)->count())->toBe(0);
});

test('on a template with no project and no center only the officer responsible for the report\'s project validates', function () {
    $template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->create([
        'project_id' => null, 'city_id' => $this->aleppo, 'program_id' => $this->project->program_id, 'center_id' => null,
    ]));
    $report = Activity::withoutRecording(fn (): MonthlyReport => MonthlyReport::factory()->submitted()->create([
        'report_template_id' => $template->id, 'project_id' => $this->project->id, 'period' => '2026-10-01',
    ]));
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal'));
    $officer = governorateOfficer($this->aleppo);
    $otherOfficer = governorateOfficer(insertHrCity('Homs'));

    expect($officer->can('validate', $report))->toBeTrue()
        ->and($meal->can('validate', $report))->toBeFalse()
        ->and($otherOfficer->can('validate', $report))->toBeFalse();
});

test('the Public Relations Manager cannot validate or return a submitted report', function () {
    governorateOfficer($this->aleppo);
    $prManager = userWithRole('Public Relations Manager');
    $submitted = monthlyReportIn($this->project, MonthlyReportStatus::Submitted, $this->owner);

    expect($prManager->can('validate', $submitted))->toBeFalse()
        ->and($prManager->can('return', $submitted))->toBeFalse();

    test()->actingAs($prManager);

    expect(fn () => app(ValidateMonthlyReport::class)->handle($submitted, MonthlyReportStatus::Submitted))->toThrow(AuthorizationException::class)
        ->and($submitted->fresh()->status)->toBe(MonthlyReportStatus::Submitted);
});

/**
 * A Y-02 template at a new center of Aleppo in a new program, carried by a
 * proposal whose row there names the given responsible.
 *
 * @return array{0: ReportTemplate, 1: Proposal, 2: int, 3: int} template, proposal, center, program
 */
function mrauPlacedTemplate(int $responsibleEmployeeId): array
{
    $center = insertHrCenter(test()->aleppo);
    $program = Activity::withoutRecording(fn (): Program => Program::factory()->create());

    return Activity::withoutRecording(function () use ($center, $program, $responsibleEmployeeId): array {
        $proposal = Proposal::factory()->create(['planned_date_begin' => '2026-10-01', 'planned_date_end' => '2026-12-31']);
        mrauRow($proposal, $program->id, $responsibleEmployeeId, $center);
        $template = ReportTemplate::factory()->forPlace(new ReportPlace(test()->aleppo, $program->id, $center, false), $proposal)->create();

        return [$template, $proposal, $center, $program->id];
    });
}

/**
 * A location row of the proposal (in Aleppo unless a city is given), at the
 * center when one is given.
 */
function mrauRow(Proposal $proposal, int $programId, int $responsibleEmployeeId, ?int $centerId, ?int $cityId = null): ProposalLocation
{
    return Activity::withoutRecording(function () use ($proposal, $programId, $responsibleEmployeeId, $centerId, $cityId): ProposalLocation {
        $row = ProposalLocation::factory()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $cityId ?? test()->aleppo,
            'program_id' => $programId,
            'responsible_employee_id' => $responsibleEmployeeId,
        ]);

        if ($centerId !== null) {
            $row->centers()->create(['center_id' => $centerId]);
        }

        return $row;
    });
}

function mrauDraftOf(ReportTemplate $template, string $period = '2026-10-01'): MonthlyReport
{
    return Activity::withoutRecording(fn (): MonthlyReport => MonthlyReport::factory()->create([
        'project_id' => null, 'report_template_id' => $template->id, 'period' => $period,
    ]));
}

test('D-84: the responsible of the template row at its place prepares, and so does their supervisor', function () {
    $responsibleEmployee = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Responsible');
    [$template] = mrauPlacedTemplate($responsibleEmployee);
    $draft = mrauDraftOf($template);
    $responsible = userWithRole('Public Relations', $responsibleEmployee);
    $supervisorEmployee = insertHrEmployee(firstNameEn: 'Hadi', lastNameEn: 'Supervisor');
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $supervisorEmployee, 'supervisee_employee_id' => $responsibleEmployee,
    ]));
    $supervisor = userWithRole('Public Relations', $supervisorEmployee);

    expect($responsible->can('open', [MonthlyReport::class, $template]))->toBeTrue()
        ->and($responsible->can('update', $draft))->toBeTrue()
        ->and($supervisor->can('open', [MonthlyReport::class, $template]))->toBeTrue()
        ->and($supervisor->can('update', $draft))->toBeTrue()
        ->and($this->owner->can('open', [MonthlyReport::class, $template]))->toBeFalse();
});

test('D-84: on a joined template each proposal responsible at the place prepares', function () {
    $first = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'First');
    $second = insertHrEmployee(firstNameEn: 'Sami', lastNameEn: 'Second');
    [$template, , $center, $programId] = mrauPlacedTemplate($first);
    $joined = Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create());
    mrauRow($joined, $programId, $second, $center);
    $template->proposals()->attach($joined->id, ['joined_at' => now()]);
    $draft = mrauDraftOf($template);

    foreach ([$first, $second] as $employee) {
        $user = userWithRole('Public Relations', $employee);

        expect($user->can('open', [MonthlyReport::class, $template]))->toBeTrue()
            ->and($user->can('update', $draft))->toBeTrue();
    }
});

test('D-84: the responsible of the same proposal at another place, or of a proposal not on the template, is refused', function () {
    $here = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Here');
    [$template, $proposal, , $programId] = mrauPlacedTemplate($here);
    $elsewhere = insertHrEmployee(firstNameEn: 'Ziad', lastNameEn: 'Elsewhere');
    $homs = insertHrCity('Homs');
    mrauRow($proposal, $programId, $elsewhere, insertHrCenter($homs), $homs);
    $outsider = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Outsider');
    mrauRow(Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create()), $programId, $outsider, $template->center_id);
    $draft = mrauDraftOf($template);

    foreach ([$elsewhere, $outsider] as $employee) {
        $user = userWithRole('Public Relations', $employee);

        expect($user->can('open', [MonthlyReport::class, $template]))->toBeFalse()
            ->and($user->can('update', $draft))->toBeFalse();
    }

    $this->actingAs(userWithRole('Public Relations', $elsewhere));

    expect(fn () => app(OpenMonthlyReport::class)->handle($template, CarbonImmutable::parse('2026-10-01')))
        ->toThrow(AuthorizationException::class);
});

test('D-84: the responsible without reports.create or reports.update is refused', function () {
    $responsibleEmployee = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Responsible');
    [$template] = mrauPlacedTemplate($responsibleEmployee);
    $draft = mrauDraftOf($template);
    $viewer = userWithRole('Team Member', $responsibleEmployee);

    expect($viewer->can('open', [MonthlyReport::class, $template]))->toBeFalse()
        ->and($viewer->can('update', $draft))->toBeFalse()
        ->and($viewer->can('submit', $draft))->toBeFalse();
});

test('D-84: a migrated template with no center is prepared by its row responsible', function () {
    $template = mrauTemplate($this->project);

    expect($template->center_id)->toBeNull()
        ->and($template->city_wide)->toBeFalse()
        ->and($this->owner->can('open', [MonthlyReport::class, $template]))->toBeTrue();
});

test('BOLA: the preparer of one template cannot touch a report of another template', function () {
    $responsibleEmployee = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Responsible');
    [$template] = mrauPlacedTemplate($responsibleEmployee);
    mrauDraftOf($template);
    $foreign = monthlyReportIn($this->project, MonthlyReportStatus::Draft);
    $responsible = userWithRole('Public Relations', $responsibleEmployee);

    expect($responsible->can('update', $foreign))->toBeFalse()
        ->and($responsible->can('delete', $foreign))->toBeFalse();
});
