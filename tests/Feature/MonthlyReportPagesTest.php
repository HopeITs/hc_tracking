<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportReopenRequest;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

/*
| The Monthly Reports list (T17B slice 1; contracts §13, §13.1 items 2, 6
| and 9; D-08, D-12a, D-29). "My reports" lists every generated month of the
| user's projects with its state, due date and overdue marker; "To validate"
| is MonthlyReport::validatableBy() limited to submitted reports.
*/

beforeEach(function () {
    $this->aleppo = insertHrCity('Aleppo');
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->owner = userWithRole('Public Relations', $this->ownerEmployee);
});

function mrpProject(int $cityId, ?int $ownerEmployee = null, string $start = '2026-07-01', string $end = '2027-01-31', string $name = 'Hope Center Aleppo'): Project
{
    return Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'name' => $name,
        'city_id' => $cityId,
        'center_id' => null,
        'user_id' => $ownerEmployee ?? test()->ownerEmployee,
        'start_date' => $start,
        'end_date' => $end,
    ]));
}

/**
 * A month of the project in the given status, with one line so it is a
 * required month (§13.1 item 2).
 *
 * @param  array<string, mixed>  $attributes
 */
function mrpMonth(Project $project, string $period, MonthlyReportStatus $status = MonthlyReportStatus::Draft, array $attributes = []): MonthlyReport
{
    return Activity::withoutRecording(function () use ($project, $period, $status, $attributes): MonthlyReport {
        $factory = match ($status) {
            MonthlyReportStatus::Draft => MonthlyReport::factory(),
            MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted(),
            MonthlyReportStatus::Validated => MonthlyReport::factory()->validated(),
        };
        $report = $factory->create(['project_id' => $project->id, 'period' => $period, ...$attributes]);
        MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id]);

        return $report;
    });
}

test('guests are redirected and every reports.view holder opens the list', function () {
    $this->get(route('monthly-reports.index'))->assertRedirect(route('login'));

    $this->actingAs(userWithRole('Team Member'))->get(route('monthly-reports.index'))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('monthly-reports.index'))->assertForbidden();
});

test('a center manager sees every generated month with its state, and only a started late draft is overdue', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $project = mrpProject($this->aleppo);
    mrpMonth($project, '2026-07-01', MonthlyReportStatus::Validated);
    $august = mrpMonth($project, '2026-08-01', MonthlyReportStatus::Validated);
    Activity::withoutRecording(fn () => MonthlyReportReopenRequest::factory()->create(['monthly_report_id' => $august->id, 'requested_by' => $this->owner->id]));
    mrpMonth($project, '2026-09-01', MonthlyReportStatus::Submitted);
    mrpMonth($project, '2026-10-01', attributes: ['returned_at' => '2026-11-05 09:00:00', 'returned_by' => User::factory(), 'return_reason' => 'Figures do not match the attendance sheets.']);
    $november = mrpMonth($project, '2026-11-01');
    mrpMonth($project, '2026-12-01');

    $component = Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSet('tab', 'mine')
        ->assertSeeHtml('data-month="2026-07" data-month-state="validated"')
        ->assertSeeHtml('data-month="2026-08" data-month-state="reopen_pending"')
        ->assertSeeHtml('data-month="2026-09" data-month-state="submitted"')
        ->assertSeeHtml('data-month="2026-10" data-month-state="returned"')
        ->assertSeeHtml('data-month="2026-11" data-month-state="draft"')
        ->assertSeeHtml('data-month="2026-12" data-month-state="upcoming"')
        ->assertSeeHtml('data-overdue="2026-10"')
        ->assertDontSeeHtml('data-overdue="2026-11"')
        ->assertDontSeeHtml('data-overdue="2026-12"')
        ->assertSeeHtml('data-fill="'.$november->id.'"')
        ->assertSeeText(__('monthly_reports.states.reopen_pending'))
        ->assertSeeText(__('monthly_reports.states.upcoming'));

    $component->call('fillMonth', $november->id)->assertRedirect(route('monthly-reports.show', $november));
});

test('an upcoming month has no Fill button, only its view link', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $december = mrpMonth(mrpProject($this->aleppo), '2026-12-01');

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertDontSeeHtml('data-fill="'.$december->id.'"')
        ->assertSeeHtml(route('monthly-reports.show', $december));
});

test('a month blocked by an unsubmitted earlier month can still be filled and shows why it cannot be submitted', function () {
    $this->travelTo('2026-12-01 10:00:00');
    $project = mrpProject($this->aleppo, start: '2026-10-01', end: '2026-12-31');
    mrpMonth($project, '2026-10-01');
    $november = mrpMonth($project, '2026-11-01');

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSeeText(__('monthly_reports.submit_previous_first', ['month' => 'October 2026']))
        ->assertSeeHtml('data-fill="'.$november->id.'"')
        ->call('fillMonth', $november->id)
        ->assertRedirect(route('monthly-reports.show', $november));
});

test('a user with no project of their own is told why "My reports" is empty', function () {
    Livewire::actingAs(userWithRole('Public Relations', insertHrEmployee()))->test('pages::monthly-reports.index')
        ->assertSeeText(__('monthly_reports.empty_mine'));
});

test('MEAL lands on its queue and sees only the submitted reports of the projects it covers', function () {
    $officerEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($this->aleppo)->create(['employee_id' => $officerEmployee]));
    $officer = userWithRole('MEAL', $officerEmployee);
    $covered = mrpProject($this->aleppo);
    $homsEmployee = insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Homs');
    $homs = insertHrCity('Homs');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($homs)->create(['employee_id' => $homsEmployee]));
    $elsewhere = mrpProject($homs, name: 'Homs Center');
    $submitted = mrpMonth($covered, '2026-09-01', MonthlyReportStatus::Submitted);
    $draft = mrpMonth($covered, '2026-10-01');
    $foreign = mrpMonth($elsewhere, '2026-09-01', MonthlyReportStatus::Submitted);

    Livewire::actingAs($officer)->test('pages::monthly-reports.index')
        ->assertSet('tab', 'validate')
        ->assertSeeHtml('data-validate-row="'.$submitted->id.'"')
        ->assertDontSeeHtml('data-validate-row="'.$draft->id.'"')
        ->assertDontSeeHtml('data-validate-row="'.$foreign->id.'"');
});

test('a project officer from another governorate sees the project while the governorate officer does not', function () {
    $governorateEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($this->aleppo)->create(['employee_id' => $governorateEmployee]));
    $idlibEmployee = insertHrEmployee(firstNameEn: 'Ziad', lastNameEn: 'Idlib');
    $project = mrpProject($this->aleppo);
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity(insertHrCity('Idlib'))->create(['employee_id' => $idlibEmployee]));
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($project->id)->create(['employee_id' => $idlibEmployee]));
    $report = mrpMonth($project, '2026-09-01', MonthlyReportStatus::Submitted);

    Livewire::actingAs(userWithRole('MEAL', $idlibEmployee))->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-validate-row="'.$report->id.'"');

    Livewire::actingAs(userWithRole('MEAL', $governorateEmployee))->test('pages::monthly-reports.index')
        ->assertDontSeeHtml('data-validate-row="'.$report->id.'"')
        ->assertSeeText(__('monthly_reports.empty_validate'));
});

test('with nobody responsible for the project any MEAL user sees it to validate', function () {
    $report = mrpMonth(mrpProject($this->aleppo), '2026-09-01', MonthlyReportStatus::Submitted);

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-validate-row="'.$report->id.'"');
});

test('the queue filters by city, project and month', function () {
    $project = mrpProject($this->aleppo);
    $other = mrpProject($homs = insertHrCity('Homs'), name: 'Homs Center');
    $september = mrpMonth($project, '2026-09-01', MonthlyReportStatus::Submitted);
    $october = mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted);
    $homsReport = mrpMonth($other, '2026-09-01', MonthlyReportStatus::Submitted);
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal'));

    Livewire::actingAs($meal)->test('pages::monthly-reports.index')
        ->set('city', (string) $homs)
        ->assertSeeHtml('data-validate-row="'.$homsReport->id.'"')
        ->assertDontSeeHtml('data-validate-row="'.$september->id.'"')
        ->set('city', '')
        ->set('project', (string) $project->id)
        ->set('month', '2026-10')
        ->assertSeeHtml('data-validate-row="'.$october->id.'"')
        ->assertDontSeeHtml('data-validate-row="'.$september->id.'"')
        ->assertDontSeeHtml('data-validate-row="'.$homsReport->id.'"');
});

test('a Team Member sees the months of a project they own without Fill and is refused the call', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $november = mrpMonth(mrpProject($this->aleppo), '2026-11-01');
    $viewer = userWithRole('Team Member', $this->ownerEmployee);

    Livewire::actingAs($viewer)->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-month="2026-11" data-month-state="draft"')
        ->assertDontSeeHtml('data-fill="'.$november->id.'"')
        ->call('fillMonth', $november->id)
        ->assertForbidden();

    expect($november->fresh()->prepared_by)->toBeNull();
});
