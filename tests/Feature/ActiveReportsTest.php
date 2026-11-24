<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Active Reports (M-02): one table of every report still open: monthly
| reports, donor reports and the existing (narrative) reports. Each viewer
| sees only the reports they may open today; permissions are unchanged.
| Open means: monthly draft or submitted, donor report not yet submitted,
| report draft or reviewed.
*/

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    $this->aleppo = insertHrCity('Aleppo');
    $this->mealEmployee = insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal');
    $this->meal = userWithRole('MEAL', $this->mealEmployee);
    $this->preparerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->preparer = userWithRole('Public Relations', $this->preparerEmployee);
});

/**
 * One place with a confirmed template, a proposal and the given months.
 *
 * @param  array<string, MonthlyReportStatus>  $months  'Y-m-d' => status
 * @return array{template: ReportTemplate, proposal: Proposal, reports: array<string, MonthlyReport>}
 */
function arpTemplate(int $cityId, string $proposalName, array $months, ?int $responsibleEmployeeId = null): array
{
    return Activity::withoutRecording(function () use ($cityId, $proposalName, $months, $responsibleEmployeeId): array {
        $place = new ReportPlace($cityId, Program::factory()->create()->id, insertHrCenter($cityId, $proposalName.' Center'), false);
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
 * A donor report on a fresh agreement.
 *
 * @param  array<string, mixed>  $attributes
 */
function arpDonorReport(string $proposalName, array $attributes = [], bool $submitted = false): DonorReport
{
    return Activity::withoutRecording(function () use ($proposalName, $attributes, $submitted): DonorReport {
        $donor = Donor::factory()->create();
        $proposal = Proposal::factory()->running()->create(['donor_id' => $donor->id, 'name' => $proposalName]);
        $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $donor->id]);
        $factory = $submitted ? DonorReport::factory()->submitted() : DonorReport::factory();

        return $factory->create(['donor_proposal_id' => $agreement->id, ...$attributes]);
    });
}

/**
 * Everything the MEAL user may see, one open and one closed of each type.
 *
 * @return array<string, mixed>
 */
function arpSeedAllTypes(): array
{
    $place = arpTemplate(test()->aleppo, 'Education for All', [
        '2026-09-01' => MonthlyReportStatus::Validated,
        '2026-10-01' => MonthlyReportStatus::Submitted,
        '2026-11-01' => MonthlyReportStatus::Draft,
    ]);

    return Activity::withoutRecording(fn (): array => [
        'monthlyClosed' => $place['reports']['2026-09-01'],
        'monthlyOpen' => $place['reports']['2026-10-01'],
        'monthlyDraftElsewhere' => $place['reports']['2026-11-01'],
        'donorOpen' => arpDonorReport('Safe Schools', ['title' => 'Q4 narrative', 'due_on' => '2026-11-25', 'responsible_employee_id' => test()->mealEmployee]),
        'donorClosed' => arpDonorReport('Winter Shelter', ['title' => 'Final financial', 'due_on' => '2026-10-01'], submitted: true),
        'reportOpen' => Report::factory()->create(['user_id' => test()->meal->id, 'planned_date' => '2026-11-10']),
        'reportReviewed' => Report::factory()->reviewed()->create(['planned_date' => '2026-11-05']),
        'reportClosed' => Report::factory()->approved()->create(['planned_date' => '2026-10-05']),
        'reportMirror' => Report::factory()->create(['monthly_report_id' => $place['reports']['2026-10-01']->id, 'planned_date' => '2026-10-31']),
    ]);
}

test('guests are sent to sign in and a user without reports.view is refused', function () {
    $this->get(route('active-reports.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('active-reports.index'))->assertForbidden();
    $this->actingAs($this->meal)->get(route('active-reports.index'))->assertOk();
});

test('open reports of all three types are listed and closed ones are not', function () {
    $seed = arpSeedAllTypes();

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->set('tab', 'all');

    $page->assertSeeHtml('data-active-report="monthly-'.$seed['monthlyOpen']->id.'"')
        ->assertSeeHtml('data-active-report="donor-'.$seed['donorOpen']->id.'"')
        ->assertSeeHtml('data-active-report="report-'.$seed['reportOpen']->id.'"')
        ->assertSeeHtml('data-active-report="report-'.$seed['reportReviewed']->id.'"')
        ->assertDontSeeHtml('data-active-report="monthly-'.$seed['monthlyClosed']->id.'"')
        ->assertDontSeeHtml('data-active-report="donor-'.$seed['donorClosed']->id.'"')
        ->assertDontSeeHtml('data-active-report="report-'.$seed['reportClosed']->id.'"')
        // A draft of somebody else's template is not the validator's to see.
        ->assertDontSeeHtml('data-active-report="monthly-'.$seed['monthlyDraftElsewhere']->id.'"')
        // A mirror is its monthly report, listed once as that.
        ->assertDontSeeHtml('data-active-report="report-'.$seed['reportMirror']->id.'"')
        ->assertSeeText(['Education for All', 'Safe Schools', 'Q4 narrative']);
});

test('a preparer sees their started drafts and submitted months, not months still to come', function () {
    $mine = arpTemplate($this->aleppo, 'Child Protection', [
        '2026-10-01' => MonthlyReportStatus::Draft,
        '2026-11-01' => MonthlyReportStatus::Submitted,
        '2026-12-01' => MonthlyReportStatus::Draft,
    ], $this->preparerEmployee);
    $notMine = arpTemplate($this->aleppo, 'Hidden Elsewhere', ['2026-10-01' => MonthlyReportStatus::Draft], insertHrEmployee(firstNameEn: 'Other', lastNameEn: 'Person'));

    Livewire::actingAs($this->preparer)->test('pages::active-reports.index')
        ->set('tab', 'all')
        ->assertSeeHtml('data-active-report="monthly-'.$mine['reports']['2026-10-01']->id.'"')
        ->assertSeeHtml('data-active-report="monthly-'.$mine['reports']['2026-11-01']->id.'"')
        ->assertDontSeeHtml('data-active-report="monthly-'.$mine['reports']['2026-12-01']->id.'"')
        ->assertDontSeeHtml('data-active-report="monthly-'.$notMine['reports']['2026-10-01']->id.'"')
        ->set('tab', 'needs')
        ->assertSeeHtml('data-active-report="monthly-'.$mine['reports']['2026-10-01']->id.'"')
        // Submitted: it waits on MEAL, not on the preparer.
        ->assertDontSeeHtml('data-active-report="monthly-'.$mine['reports']['2026-11-01']->id.'"');
});

test('"Needs me" lists only what waits on the viewer', function () {
    $seed = arpSeedAllTypes();
    $otherDonor = arpDonorReport('Elsewhere Proposal', ['title' => 'Not mine', 'due_on' => '2026-11-30', 'responsible_employee_id' => insertHrEmployee(firstNameEn: 'Other', lastNameEn: 'Person')]);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->set('tab', 'needs')
        // Submitted monthly report to validate, own donor report, own draft report, someone's report to approve.
        ->assertSeeHtml('data-active-report="monthly-'.$seed['monthlyOpen']->id.'"')
        ->assertSeeHtml('data-active-report="donor-'.$seed['donorOpen']->id.'"')
        ->assertSeeHtml('data-active-report="report-'.$seed['reportOpen']->id.'"')
        ->assertSeeHtml('data-active-report="report-'.$seed['reportReviewed']->id.'"')
        ->assertDontSeeHtml('data-active-report="donor-'.$otherDonor->id.'"')
        ->set('tab', 'all')
        ->assertSeeHtml('data-active-report="donor-'.$otherDonor->id.'"');
});

test('the type filter, the status filter and the search narrow the table', function () {
    $seed = arpSeedAllTypes();

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->set('tab', 'all');

    $page->set('type', 'donor')
        ->assertSeeHtml('data-active-report="donor-'.$seed['donorOpen']->id.'"')
        ->assertDontSeeHtml('data-active-report="monthly-'.$seed['monthlyOpen']->id.'"')
        ->assertDontSeeHtml('data-active-report="report-'.$seed['reportOpen']->id.'"')
        ->set('type', 'bogus')->assertSet('type', '')
        ->set('status', 'reviewed')
        ->assertSeeHtml('data-active-report="report-'.$seed['reportReviewed']->id.'"')
        ->assertDontSeeHtml('data-active-report="report-'.$seed['reportOpen']->id.'"')
        ->set('status', '')
        ->set('search', 'Safe Schools')
        ->assertSeeHtml('data-active-report="donor-'.$seed['donorOpen']->id.'"')
        ->assertDontSeeHtml('data-active-report="monthly-'.$seed['monthlyOpen']->id.'"');
});

test('a row opens its own record page and no money is read', function () {
    $seed = arpSeedAllTypes();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->set('tab', 'all');
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    $page->assertSee(route('monthly-reports.show', $seed['monthlyOpen']), false)
        ->assertSee(route('donor-reports.show', $seed['donorOpen']), false)
        ->assertSee(route('reports.show', $seed['reportOpen']), false);

    expect($sql)->not->toContain('transfers')->not->toContain('accounts')->not->toContain('`amount')->not->toContain('salary');
});

test('the query count does not grow with the number of reports', function () {
    arpSeedAllTypes();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->meal)->test('pages::active-reports.index')->set('tab', 'all');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count(); // Warm the once-per-process lookups (HR directory, schema checks).
    $few = $count();

    foreach (['One', 'Two', 'Three'] as $name) {
        arpTemplate($this->aleppo, 'Extra '.$name, ['2026-10-01' => MonthlyReportStatus::Submitted, '2026-11-01' => MonthlyReportStatus::Submitted]);
        arpDonorReport('Extra donor '.$name, ['due_on' => '2026-12-01']);
        Activity::withoutRecording(fn () => Report::factory()->count(2)->create(['planned_date' => '2026-11-02']));
    }

    expect($count())->toBe($few);
});

test('the Board and Portfolio views of the reports register land on Active Reports, keeping the search', function () {
    $this->actingAs($this->meal);

    $this->get(route('reports.index', ['view' => 'board', 'search' => 'water']))->assertRedirect(route('active-reports.index', ['search' => 'water']));
    $this->get(route('reports.index', ['view' => 'portfolio']))->assertRedirect(route('active-reports.index'));
    $this->get(route('reports.index', ['view' => 'calendar']))->assertRedirect(route('active-reports.index'));
    $this->get(route('reports.index'))->assertOk();
    $this->get(route('monthly-reports.index', ['status' => 'all']))->assertOk();
    $this->get(route('donor-reports.index'))->assertOk();
});

test('the reports register has no view switch any more', function () {
    Livewire::actingAs($this->meal)->test('pages::reports.index')
        ->assertDontSeeHtml('data-desk-register-views');
});
