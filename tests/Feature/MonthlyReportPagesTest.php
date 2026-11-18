<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportReopenRequest;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Models\Supervision;
use App\Models\User;
use App\Support\MonthlyReportNames;
use App\Support\Reporting\ReportPlace;
use Illuminate\Support\Facades\DB;
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

test('the queue filters by city, proposal and window', function () {
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
        ->set('proposal', (string) $october->template->proposals()->value('proposals.id'))
        ->set('from', '2026-10')
        ->set('to', '2026-10')
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

/*
| Page review, 5 Nov 2026: what a first-time MEAL Manager and preparer
| tripped over on this list.
*/

test('a proposal kept while the status changes still names itself on its filter', function () {
    $project = mrpProject($this->aleppo);
    mrpMonth($project, '2026-09-01', MonthlyReportStatus::Validated);
    $october = mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted);
    $meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal'));
    $proposalTrigger = fn (string $choice): string => 'aria-label="'.e(__('common.filter_choice', ['label' => __('reports.matrix.proposal'), 'choice' => $choice])).'"';
    $proposal = $october->template->proposals()->first(['proposals.id', 'proposals.name']);

    Livewire::actingAs($meal)->test('pages::monthly-reports.index')
        ->set('proposal', (string) $proposal->id)
        ->set('status', 'validated')
        ->assertSeeHtml($proposalTrigger($proposal->name))
        ->assertDontSeeHtml($proposalTrigger(__('reports.matrix.all_proposals')));
});

test('the queue never lists a draft, even for every status', function () {
    $project = mrpProject($this->aleppo);
    $draft = mrpMonth($project, '2026-11-01');
    $submitted = mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted);
    $validated = mrpMonth($project, '2026-09-01', MonthlyReportStatus::Validated);

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->set('status', 'all')
        ->assertSeeHtml('data-validate-row="'.$submitted->id.'"')
        ->assertSeeHtml('data-validate-row="'.$validated->id.'"')
        ->assertDontSeeHtml('data-validate-row="'.$draft->id.'"');
});

test('"Not seen yet" marks only a submitted report still waiting for its first look', function () {
    $project = mrpProject($this->aleppo);
    mrpMonth($project, '2026-09-01', MonthlyReportStatus::Validated, ['seen_by_meal_at' => null]);
    mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted, ['seen_by_meal_at' => null]);

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->assertSeeText(__('monthly_reports.not_seen_yet'))
        ->set('status', 'validated')
        ->assertDontSeeText(__('monthly_reports.not_seen_yet'));
});

test('active filters offer one way back, and an empty result does not mention a search', function () {
    mrpMonth(mrpProject($this->aleppo), '2026-09-01', MonthlyReportStatus::Submitted);

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->set('from', '2026-01')
        ->set('to', '2026-01')
        ->assertSeeText(__('desk.register.clear'))
        ->assertDontSeeText(__('common.reset_filters'))
        ->assertSeeText(__('monthly_reports.empty_filtered'))
        ->assertSeeText(__('common.change_the_filters_and_try_again'))
        ->assertDontSeeText(__('common.change_the_search_or_filters_and_try_again'));
});

test('each month\'s view link on "My reports" names its proposals and place', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $hope = mrpMonth(mrpProject($this->aleppo, name: 'Hope Center Aleppo'), '2026-09-01');
    $micro = mrpMonth(mrpProject($this->aleppo, name: 'Micro Aleppo'), '2026-09-01');
    $place = fn (MonthlyReport $report): string => app(MonthlyReportNames::class)->place($report->template);

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSeeHtml('aria-label="'.e(__('common.view_name', ['name' => 'Hope Center Aleppo · '.$place($hope).' · September 2026'])).'"')
        ->assertSeeHtml('aria-label="'.e(__('common.view_name', ['name' => 'Micro Aleppo · '.$place($micro).' · September 2026'])).'"');
});

test('someone who cannot validate is not offered an empty "To validate" tab', function () {
    $this->travelTo('2026-11-20 10:00:00');
    mrpMonth(mrpProject($this->aleppo), '2026-09-01');

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertDontSeeHtml('data-desk-register-view="validate"')
        ->assertSet('tab', 'mine');

    Livewire::withQueryParams(['tab' => 'validate'])->actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSet('tab', 'mine');

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-desk-register-view="validate"');
});

/*
| Page redesign, 6 Nov 2026: a figures line over each tab, a "Needs you"
| list at the top of "My reports", and how long each submitted report has
| been waiting in the validation queue.
*/

/**
 * The preparer's months of one project on 20 Nov 2026: September with MEAL,
 * October returned (so overdue), November a draft, December not started.
 *
 * @return array{october: MonthlyReport, november: MonthlyReport}
 */
function mrpPreparerMonths(Project $project): array
{
    test()->travelTo('2026-11-20 10:00:00');
    mrpMonth($project, '2026-08-01', MonthlyReportStatus::Validated);
    mrpMonth($project, '2026-09-01', MonthlyReportStatus::Submitted);
    $october = mrpMonth($project, '2026-10-01', attributes: ['returned_at' => '2026-11-05 09:00:00', 'returned_by' => User::factory(), 'return_reason' => 'Figures do not match the attendance sheets.']);
    $november = mrpMonth($project, '2026-11-01');
    mrpMonth($project, '2026-12-01');

    return ['october' => $october, 'november' => $november];
}

test('"Needs you" lists the months the preparer can fill, overdue first, above the project threads', function () {
    mrpPreparerMonths(mrpProject($this->aleppo));

    $html = Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSeeText(__('monthly_reports.needs_you'))
        ->html();

    $october = strpos($html, 'data-needs-you-item="2026-10"');
    $november = strpos($html, 'data-needs-you-item="2026-11"');

    expect($october)->not->toBeFalse()
        ->and($november)->not->toBeFalse()
        ->and($october)->toBeLessThan($november)
        ->and($html)->not->toContain('data-needs-you-item="2026-09"')
        ->and($html)->not->toContain('data-needs-you-item="2026-12"')
        ->and(strpos($html, 'data-needs-you'))->toBeLessThan(strpos($html, 'data-month="2026-08"'));
});

test('someone who reads the months but cannot fill them has no "Needs you" list', function () {
    mrpPreparerMonths(mrpProject($this->aleppo));

    Livewire::actingAs(userWithRole('Team Member', $this->ownerEmployee))->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-month="2026-11"')
        ->assertDontSeeHtml('data-needs-you');
});

test('the figures line sums up "My reports"', function () {
    mrpPreparerMonths(mrpProject($this->aleppo));

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-figure="to_fill" data-value="2"')
        ->assertSeeHtml('data-figure="overdue" data-value="1"')
        ->assertSeeHtml('data-figure="with_meal" data-value="1"')
        ->assertSeeHtml('data-figure="validated" data-value="1"');
});

test('the figures line sums up the validation queue, and follows every filter but the figure itself', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $project = mrpProject($this->aleppo);
    mrpMonth($project, '2026-09-01', MonthlyReportStatus::Validated, ['seen_by_meal_at' => '2026-10-03 09:00:00']);
    mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted, ['seen_by_meal_at' => null]);
    mrpMonth($project, '2026-11-01');

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-figure="waiting" data-value="1"')
        ->assertSeeHtml('data-figure="not_seen" data-value="1"')
        ->assertSeeHtml('data-figure="validated" data-value="1"')
        ->set('from', '2026-09')
        ->set('to', '2026-09')
        ->assertSeeHtml('data-figure="waiting" data-value="0"')
        ->assertSeeHtml('data-figure="validated" data-value="1"')
        ->set('status', 'validated')
        ->assertSeeHtml('data-figure="waiting" data-value="0"');
});

test('a submitted report in the queue says how long it has waited', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $project = mrpProject($this->aleppo);
    $waiting = mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted, ['submitted_at' => '2026-11-17 09:00:00']);
    $validated = mrpMonth($project, '2026-09-01', MonthlyReportStatus::Validated);

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->set('status', 'all')
        ->assertSeeHtml('data-waiting="'.$waiting->id.'"')
        ->assertSeeText(trans_choice('monthly_reports.waiting_days', 3, ['count' => 3]))
        ->assertDontSeeHtml('data-waiting="'.$validated->id.'"');
});

/*
| Fill that did nothing, 6 Nov 2026: a project with no start or end date has
| no period, so its months can never be opened. The button was offered
| anyway and its refusal was written to an error nobody displayed.
*/

test('a Fill the server refuses says why instead of doing nothing', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $project = mrpProject($this->aleppo);
    $november = mrpMonth($project, '2026-11-01');
    $page = Livewire::actingAs($this->owner)->test('pages::monthly-reports.index');
    // Y-03: the period is the template's proposals' window; it now ends before the month.
    DB::table('proposals')->whereIn('id', $november->template->proposals()->pluck('proposals.id'))
        ->update(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-06-30', 'actual_date_begin' => null, 'actual_date_end' => null, 'extended_date_end' => null]);

    $page->call('fillMonth', $november->id)
        ->assertNoRedirect()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === __('monthly_reports.outside_period'));
});

/*
| Layout redesign, 6 Nov 2026: big figures that filter the list, a month
| strip per project with the validated months folded away, one notice per
| project instead of a line under every month, and the queue grouped by
| project.
*/

test('the big figures filter "My reports" to the months they count, and a second click clears it', function () {
    mrpPreparerMonths(mrpProject($this->aleppo));

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->call('showOnly', 'overdue')
        ->assertSet('show', 'overdue')
        ->assertSeeHtml('data-figure="overdue" data-value="1" aria-pressed="true"')
        ->assertSeeHtml('data-month="2026-10"')
        ->assertDontSeeHtml('data-month="2026-11"')
        ->assertDontSeeHtml('data-month="2026-08"')
        ->assertDontSeeHtml('data-needs-you')
        ->call('showOnly', 'overdue')
        ->assertSet('show', '')
        ->assertSeeHtml('data-month="2026-11"');
});

test('a template shows its months as a strip and folds the validated ones away', function () {
    $project = mrpProject($this->aleppo);
    $templateId = mrpPreparerMonths($project)['november']->report_template_id;

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-month-strip="'.$templateId.'"')
        ->assertSeeHtml('data-strip-month="2026-08" data-strip-state="validated"')
        ->assertSeeHtml('data-strip-month="2026-10" data-strip-state="overdue"')
        ->assertSeeHtml('data-validated-fold="'.$templateId.'"')
        ->call('showOnly', 'validated')
        ->assertDontSeeHtml('data-validated-fold="'.$templateId.'"')
        ->assertSeeHtml('data-month="2026-08"');
});

test('a template that lost its proposals has no preparer, so it leaves "My reports"', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $november = mrpMonth(mrpProject($this->aleppo), '2026-11-01');
    $november->template->proposals()->detach();

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertDontSeeHtml('data-month="2026-11"')
        ->assertSeeText(__('monthly_reports.empty_mine'));
});

test('the validation queue groups its rows by template, and its figures switch the status', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $first = mrpProject($this->aleppo, name: 'Aleppo Center');
    $second = mrpProject($this->aleppo, name: 'Micro Aleppo');
    // One program for both, so the groups sort by their proposals within the one place.
    DB::table('projects')->where('id', $second->id)->update(['program_id' => $first->program_id]);
    $firstReport = mrpMonth($first, '2026-10-01', MonthlyReportStatus::Submitted);
    $secondReport = mrpMonth($second->fresh(), '2026-10-01', MonthlyReportStatus::Submitted);
    mrpMonth($first, '2026-09-01', MonthlyReportStatus::Validated);

    $page = Livewire::actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index');
    $html = $page->html();

    expect(strpos($html, 'data-queue-template="'.$firstReport->report_template_id.'"'))->toBeLessThan(strpos($html, 'data-validate-row="'.$firstReport->id.'"'))
        ->and(strpos($html, 'data-validate-row="'.$firstReport->id.'"'))->toBeLessThan(strpos($html, 'data-queue-template="'.$secondReport->report_template_id.'"'))
        ->and(strpos($html, 'data-queue-template="'.$secondReport->report_template_id.'"'))->toBeLessThan(strpos($html, 'data-validate-row="'.$secondReport->id.'"'));

    $page->call('showOnly', 'validated')->assertSet('status', 'validated')
        ->call('showOnly', 'waiting')->assertSet('status', 'submitted');
});

/*
| Y-03 slice 3 (D-76, D-84): "My reports" lists the templates the user
| prepares, one group per template. Two templates of one place and month are
| told apart by the proposals each one covers.
*/

/**
 * A confirmed Y-02 template at the place, carried by a new proposal whose
 * row there names the responsible, with a draft October and November.
 *
 * @return array{template: ReportTemplate, october: MonthlyReport, november: MonthlyReport}
 */
function mrpPlacedTemplate(ReportPlace $place, string $proposalName, int $responsibleEmployeeId, ?int $rowCityId = null): array
{
    return Activity::withoutRecording(function () use ($place, $proposalName, $responsibleEmployeeId, $rowCityId): array {
        $proposal = Proposal::factory()->create(['name' => $proposalName, 'planned_date_begin' => '2026-07-01', 'planned_date_end' => '2027-01-31']);
        $row = ProposalLocation::factory()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $rowCityId ?? $place->cityId,
            'program_id' => $place->programId,
            'responsible_employee_id' => $responsibleEmployeeId,
        ]);
        $row->centers()->create(['center_id' => $place->centerId]);
        $template = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
        $months = [];

        foreach (['october' => '2026-10-01', 'november' => '2026-11-01'] as $key => $period) {
            $months[$key] = MonthlyReport::factory()->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => $period]);
            MonthlyReportLine::factory()->create(['monthly_report_id' => $months[$key]->id]);
        }

        return ['template' => $template, ...$months];
    });
}

function mrpPlace(int $cityId, ?int $centerId = null): ReportPlace
{
    $programId = Activity::withoutRecording(fn (): int => Program::factory()->create()->id);

    return new ReportPlace($cityId, $programId, $centerId ?? insertHrCenter($cityId, 'Zone Center'), false);
}

test('two templates of one place are two groups, each naming the proposals it covers', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $place = mrpPlace($this->aleppo);
    $education = mrpPlacedTemplate($place, 'Education for All', $this->ownerEmployee);
    $protection = mrpPlacedTemplate($place, 'Child Protection', $this->ownerEmployee);

    $html = Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-template-group="'.$education['template']->id.'"')
        ->assertSeeHtml('data-template-group="'.$protection['template']->id.'"')
        ->assertSeeHtml('data-fill="'.$education['november']->id.'"')
        ->assertSeeHtml('data-fill="'.$protection['november']->id.'"')
        ->assertSeeText(['Education for All', 'Child Protection', 'Zone Center'])
        ->html();

    // Each "Needs you" row names its proposals, so the two Novembers are not confused.
    $educationRow = strpos($html, 'data-row-place="'.$education['template']->id.'"');
    $protectionRow = strpos($html, 'data-row-place="'.$protection['template']->id.'"');

    expect($educationRow)->not->toBeFalse()
        ->and($protectionRow)->not->toBeFalse()
        ->and(substr($html, $educationRow, 1200))->toContain('Education for All')
        ->and(substr($html, $protectionRow, 1200))->toContain('Child Protection');
});

test('a supervisor sees the templates of the people they supervise; a responsible elsewhere sees none of them', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $place = mrpPlace($this->aleppo);
    $report = mrpPlacedTemplate($place, 'Education for All', $this->ownerEmployee);
    $supervisorEmployee = insertHrEmployee(firstNameEn: 'Sara', lastNameEn: 'Supervisor');
    Activity::withoutRecording(fn () => Supervision::factory()->create(['supervisor_employee_id' => $supervisorEmployee, 'supervisee_employee_id' => $this->ownerEmployee]));
    $elsewhere = insertHrEmployee(firstNameEn: 'Hadi', lastNameEn: 'Homs');
    // The same proposal at another city: its responsible prepares nothing at this place.
    Activity::withoutRecording(fn () => ProposalLocation::factory()->create(['proposal_id' => $report['template']->proposals()->value('proposals.id'), 'city_id' => insertHrCity('Homs'), 'program_id' => $place->programId, 'responsible_employee_id' => $elsewhere]));

    Livewire::actingAs(userWithRole('Public Relations', $supervisorEmployee))->test('pages::monthly-reports.index')
        ->assertSeeHtml('data-template-group="'.$report['template']->id.'"')
        ->assertSeeHtml('data-fill="'.$report['november']->id.'"');

    Livewire::actingAs(userWithRole('Public Relations', $elsewhere))->test('pages::monthly-reports.index')
        ->assertDontSeeHtml('data-template-group="'.$report['template']->id.'"')
        ->assertSeeText(__('monthly_reports.empty_mine'));
});

test('"My reports" takes a fixed number of queries whatever the number of templates per place', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $render = function (): int {
        DB::flushQueryLog();
        DB::connection('hr')->flushQueryLog();
        DB::enableQueryLog();
        DB::connection('hr')->enableQueryLog();
        Livewire::actingAs($this->owner)->test('pages::monthly-reports.index');
        $count = count(DB::getQueryLog()) + count(DB::connection('hr')->getQueryLog());
        DB::disableQueryLog();
        DB::connection('hr')->disableQueryLog();

        return $count;
    };

    $place = mrpPlace($this->aleppo);
    mrpPlacedTemplate($place, 'Education for All', $this->ownerEmployee);
    mrpPlacedTemplate($place, 'Child Protection', $this->ownerEmployee);
    $render();
    $one = $render();

    foreach (range(1, 2) as $index) {
        $more = mrpPlace($this->aleppo, insertHrCenter($this->aleppo, 'Center '.$index));
        mrpPlacedTemplate($more, 'Education '.$index, $this->ownerEmployee);
        mrpPlacedTemplate($more, 'Protection '.$index, $this->ownerEmployee);
    }

    $render();

    expect($render())->toBe($one);
});

/*
| Y-05 slice 2 (D-79): both tabs filter by Proposal · City · Program · Center
| · From / To through ReportExtraction; the big figures read every filter but
| the pressed one; a report row links to the matrix for its place, and a
| matrix cell opens this list on its month.
*/

/**
 * A confirmed template at the place, carried by a new proposal, with one
 * monthly report per given month in the given status. A responsible makes
 * them its preparer (a location row at a centered place).
 *
 * @param  array<string, MonthlyReportStatus>  $months  'Y-m-d' => status
 * @return array{template: ReportTemplate, proposal: Proposal, reports: array<string, MonthlyReport>}
 */
function mrpExtractTemplate(ReportPlace $place, string $proposalName, array $months, ?int $responsibleEmployeeId = null): array
{
    return Activity::withoutRecording(function () use ($place, $proposalName, $months, $responsibleEmployeeId): array {
        $proposal = Proposal::factory()->create(['name' => $proposalName, 'planned_date_begin' => '2026-07-01', 'planned_date_end' => '2027-01-31']);

        if ($responsibleEmployeeId !== null) {
            $row = ProposalLocation::factory()->create(['proposal_id' => $proposal->id, 'city_id' => $place->cityId, 'program_id' => $place->programId, 'responsible_employee_id' => $responsibleEmployeeId]);
            $row->centers()->create(['center_id' => $place->centerId]);
        }

        $template = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
        $reports = [];

        foreach ($months as $period => $status) {
            $factory = match ($status) {
                MonthlyReportStatus::Draft => MonthlyReport::factory(),
                MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted(),
                MonthlyReportStatus::Validated => MonthlyReport::factory()->validated(),
            };
            $reports[$period] = $factory->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => $period]);
            MonthlyReportLine::factory()->create(['monthly_report_id' => $reports[$period]->id]);
        }

        return ['template' => $template, 'proposal' => $proposal, 'reports' => $reports];
    });
}

/**
 * Four places with an October report waiting for MEAL: a center in Aleppo,
 * a center in Homs (another program), Aleppo's Field work and an Aleppo
 * place whose center is not set.
 *
 * @return array{homs: int, aleppoPlace: ReportPlace, homsPlace: ReportPlace, aleppo: array<string, mixed>, homsT: array<string, mixed>, field: array<string, mixed>, unset: array<string, mixed>}
 */
function mrpExtractQueue(int $aleppo): array
{
    $homs = insertHrCity('Homs');
    $aleppoPlace = mrpPlace($aleppo);
    $homsPlace = mrpPlace($homs);
    $october = ['2026-10-01' => MonthlyReportStatus::Submitted];

    return [
        'homs' => $homs,
        'aleppoPlace' => $aleppoPlace,
        'homsPlace' => $homsPlace,
        'aleppo' => mrpExtractTemplate($aleppoPlace, 'Education for All', $october),
        'homsT' => mrpExtractTemplate($homsPlace, 'Health Homs', $october),
        'field' => mrpExtractTemplate(new ReportPlace($aleppo, $aleppoPlace->programId, null, true), 'Field Aleppo', $october),
        'unset' => mrpExtractTemplate(new ReportPlace($aleppo, $aleppoPlace->programId, null, false), 'Unset Aleppo', $october),
    ];
}

/**
 * The ids a rendered list shows under the given attribute.
 *
 * @return list<int>
 */
function mrpRowIds(string $html, string $attribute = 'data-validate-row'): array
{
    preg_match_all('/'.$attribute.'="(\d+)"/', $html, $matches);

    return array_map('intval', $matches[1]);
}

test('Y-05: each filter narrows "To validate"', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $set = mrpExtractQueue($this->aleppo);
    $meal = mealManager();
    $id = fn (string $key): int => $set[$key]['reports']['2026-10-01']->id;
    $rows = fn (array $filters): array => mrpRowIds(Livewire::withQueryParams($filters)->actingAs($meal)->test('pages::monthly-reports.index')->assertSet('tab', 'validate')->html());

    expect($rows([]))->toEqualCanonicalizing([$id('aleppo'), $id('homsT'), $id('field'), $id('unset')])
        ->and($rows(['proposal' => (string) $set['aleppo']['proposal']->id]))->toBe([$id('aleppo')])
        ->and($rows(['city' => (string) $set['homs']]))->toBe([$id('homsT')])
        ->and($rows(['program' => (string) $set['homsPlace']->programId]))->toBe([$id('homsT')])
        ->and($rows(['center' => 'c'.$set['aleppoPlace']->centerId]))->toBe([$id('aleppo')])
        ->and($rows(['center' => 'field-'.$this->aleppo]))->toBe([$id('field')])
        ->and($rows(['center' => 'unset']))->toBe([$id('unset')])
        ->and($rows(['from' => '2026-10', 'to' => '2026-10']))->toHaveCount(4)
        ->and($rows(['from' => '2026-11']))->toBe([]);
});

test('Y-05: each filter narrows "My reports", and an open To keeps the months to come', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $aleppoPlace = mrpPlace($this->aleppo);
    $homsPlace = mrpPlace($homs = insertHrCity('Homs'));
    $months = ['2026-09-01' => MonthlyReportStatus::Submitted, '2026-10-01' => MonthlyReportStatus::Draft, '2026-11-01' => MonthlyReportStatus::Draft];
    $education = mrpExtractTemplate($aleppoPlace, 'Education for All', $months, $this->ownerEmployee);
    $health = mrpExtractTemplate($homsPlace, 'Health Homs', $months, $this->ownerEmployee);
    $render = fn (array $filters): string => Livewire::withQueryParams($filters)->actingAs($this->owner)->test('pages::monthly-reports.index')->assertSet('tab', 'mine')->html();
    $groups = fn (array $filters): array => mrpRowIds($render($filters), 'data-template-group');

    expect($groups([]))->toEqualCanonicalizing([$education['template']->id, $health['template']->id])
        // November has not started on 20 Oct: an open To still lists it.
        ->and($render([]))->toContain('data-month="2026-11" data-month-state="upcoming"')
        ->and($groups(['proposal' => (string) $health['proposal']->id]))->toBe([$health['template']->id])
        ->and($groups(['city' => (string) $homs]))->toBe([$health['template']->id])
        ->and($groups(['program' => (string) $aleppoPlace->programId]))->toBe([$education['template']->id])
        ->and($groups(['center' => 'c'.$homsPlace->centerId]))->toBe([$health['template']->id])
        ->and($render(['from' => '2026-09', 'to' => '2026-09']))->toContain('data-month="2026-09"')
        ->not->toContain('data-month="2026-10"')
        ->not->toContain('data-month="2026-11"');
});

test('Y-05: From after To is a field error and lists nothing', function () {
    $this->travelTo('2026-11-20 10:00:00');
    mrpExtractQueue($this->aleppo);

    $page = Livewire::actingAs(mealManager())->test('pages::monthly-reports.index')
        ->set('from', '2026-10')
        ->set('to', '2026-09')
        ->assertHasErrors('from')
        ->assertSeeText(__('reports.matrix.from_after_to'))
        ->assertSet('from', '2026-10');

    expect(mrpRowIds($page->html()))->toBe([]);
});

test('Y-05: each big figure counts exactly the rows it lists, with the filters on', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $education = mrpExtractTemplate(mrpPlace($this->aleppo), 'Education for All', ['2026-08-01' => MonthlyReportStatus::Validated, '2026-09-01' => MonthlyReportStatus::Submitted, '2026-10-01' => MonthlyReportStatus::Submitted], $this->ownerEmployee);
    DB::table('monthly_reports')->where('id', $education['reports']['2026-09-01']->id)->update(['seen_by_meal_at' => '2026-10-05 09:00:00']);
    DB::table('monthly_reports')->where('id', $education['reports']['2026-10-01']->id)->update(['seen_by_meal_at' => null]);
    mrpExtractTemplate(mrpPlace(insertHrCity('Homs')), 'Health Homs', ['2026-09-01' => MonthlyReportStatus::Submitted, '2026-10-01' => MonthlyReportStatus::Validated], $this->ownerEmployee);
    $figure = fn (string $html, string $key): int => preg_match('/data-figure="'.$key.'" data-value="(\d+)"/', $html, $match) === 1 ? (int) $match[1] : -1;

    $queue = Livewire::actingAs(mealManager())->test('pages::monthly-reports.index')->set('city', (string) $this->aleppo);
    $figures = ['waiting' => $figure($queue->html(), 'waiting'), 'not_seen' => $figure($queue->html(), 'not_seen'), 'validated' => $figure($queue->html(), 'validated')];
    expect($figures)->toBe(['waiting' => 2, 'not_seen' => 1, 'validated' => 1]);

    foreach ($figures as $key => $value) {
        $queue->call('showOnly', $key);
        expect(mrpRowIds($queue->html()))->toHaveCount($value);
        $queue->set('status', 'submitted')->set('show', '');
    }

    $mine = Livewire::actingAs($this->owner)->test('pages::monthly-reports.index')->set('city', (string) $this->aleppo);
    expect($figure($mine->html(), 'with_meal'))->toBe(2)
        ->and($figure($mine->html(), 'validated'))->toBe(1);

    foreach (['with_meal', 'validated'] as $key) {
        $value = $figure($mine->html(), $key);
        $mine->call('showOnly', $key);
        expect(substr_count($mine->html(), 'data-month="'))->toBe($value);
        $mine->call('showOnly', $key);
    }
});

test('Y-05: a matrix cell opens this list on its month, and a row links back to the matrix for its place', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $set = mrpExtractQueue($this->aleppo);
    $report = $set['aleppo']['reports']['2026-10-01'];
    $place = $set['aleppoPlace'];
    $meal = mealManager();

    // The matrix cell's link, as the matrix builds it: the row's place, that month, every status.
    $cell = ['proposal' => (string) $set['aleppo']['proposal']->id, 'city' => (string) $place->cityId, 'program' => (string) $place->programId, 'center' => 'c'.$place->centerId, 'from' => '2026-10', 'to' => '2026-10', 'status' => 'all'];
    $list = Livewire::withQueryParams($cell)->actingAs($meal)->test('pages::monthly-reports.index')->html();

    expect(mrpRowIds($list))->toBe([$report->id])
        ->and(preg_match('/<a[^>]*data-matrix-link="'.$report->id.'"[^>]*>/', $list, $anchor))->toBe(1)
        ->and(preg_match('/href="([^"]+)"/', $anchor[0], $hrefMatch))->toBe(1);

    $href = html_entity_decode($hrefMatch[1]);
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    expect(parse_url($href, PHP_URL_PATH))->toBe(parse_url(route('reports.monthly-matrix'), PHP_URL_PATH))
        ->and($query)->toMatchArray(['proposal' => $cell['proposal'], 'city' => $cell['city'], 'program' => $cell['program'], 'center' => $cell['center'], 'from' => '2026-10', 'to' => '2026-10']);

    Livewire::withQueryParams($query)->actingAs($meal)->test('pages::reports.monthly-matrix')
        ->assertSet('center', $cell['center'])
        ->assertSet('city', $cell['city'])
        ->assertSet('program', $cell['program'])
        ->assertSet('from', '2026-10')
        ->assertSet('to', '2026-10');

    // The Field work and Center not set rows name their own place.
    $all = html_entity_decode(Livewire::withQueryParams(['status' => 'all'])->actingAs($meal)->test('pages::monthly-reports.index')->html());
    expect($all)->toContain('center=field-'.$this->aleppo)->toContain('center=unset');
});

test('Y-05: the old ?month= and ?project= links still open, as From / To and the project\'s place', function () {
    $project = mrpProject($this->aleppo);
    $report = mrpMonth($project, '2026-10-01', MonthlyReportStatus::Submitted);

    Livewire::withQueryParams(['month' => '2026-10', 'project' => (string) $project->id])
        ->actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal')))
        ->test('pages::monthly-reports.index')
        ->assertSet('month', '')
        ->assertSet('project', '')
        ->assertSet('from', '2026-10')
        ->assertSet('to', '2026-10')
        ->assertSet('center', 'unset')
        ->assertSet('city', (string) $this->aleppo)
        ->assertSet('program', (string) $report->template->program_id)
        ->assertSeeHtml('data-validate-row="'.$report->id.'"');
});

test('Y-05: "To validate" takes a fixed number of queries whatever the number of reports', function () {
    $this->travelTo('2026-11-20 10:00:00');
    $meal = mealManager();
    $render = function () use ($meal): int {
        DB::flushQueryLog();
        DB::connection('hr')->flushQueryLog();
        DB::enableQueryLog();
        DB::connection('hr')->enableQueryLog();
        Livewire::withQueryParams(['city' => (string) $this->aleppo])->actingAs($meal)->test('pages::monthly-reports.index');
        $count = count(DB::getQueryLog()) + count(DB::connection('hr')->getQueryLog());
        DB::disableQueryLog();
        DB::connection('hr')->disableQueryLog();

        return $count;
    };

    mrpExtractTemplate(mrpPlace($this->aleppo), 'Education 1', ['2026-09-01' => MonthlyReportStatus::Submitted]);
    $render();
    $one = $render();

    foreach (range(2, 4) as $index) {
        mrpExtractTemplate(mrpPlace($this->aleppo, insertHrCenter($this->aleppo, 'Center '.$index)), 'Education '.$index, ['2026-09-01' => MonthlyReportStatus::Submitted, '2026-10-01' => MonthlyReportStatus::Submitted]);
    }

    $render();

    expect($render())->toBe($one);
});
