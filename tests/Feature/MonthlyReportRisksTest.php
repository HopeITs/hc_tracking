<?php

use App\Actions\MonthlyReports\SaveMonthlyReportRisks;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRisk;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\Risk;
use App\Support\MonthlyReportRisks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The optional "Update risks" line of a monthly report: the logframe risks of
| the proposals it covers, ticked when they happened this month.
*/

beforeEach(function () {
    $this->travelTo('2026-12-02 10:00:00');
    $city = insertHrCity('Aleppo');
    $ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->owner = userWithRole('Public Relations', $ownerEmployee);
    $officerEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Officer');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forCity($city)->create(['employee_id' => $officerEmployee]));
    $this->officer = userWithRole('MEAL', $officerEmployee);

    $this->project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'program_id' => Program::factory()->create()->id,
        'city_id' => $city,
        'center_id' => null,
        'user_id' => $ownerEmployee,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]));
    $this->proposal = Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create(['status' => ProposalStatus::Approved, 'name' => 'Proposal Alpha']));
    $this->project->proposals()->attach($this->proposal->id, ['target_beneficiaries' => 100]);
    $this->template = Activity::withoutRecording(fn (): ReportTemplate => ReportTemplate::factory()->create(['project_id' => $this->project->id]));
    $this->template->proposals()->attach($this->proposal->id, ['joined_at' => now()]);

    $this->risk = Activity::withoutRecording(fn (): Risk => Risk::factory()->onProposalFramework($this->proposal)->create(['name' => 'Road closure', 'happened' => true]));
    $this->quietRisk = Activity::withoutRecording(fn (): Risk => Risk::factory()->onProposalFramework($this->proposal)->create(['name' => 'Staff turnover', 'happened' => false]));
});

function mrrReport(MonthlyReportStatus $status = MonthlyReportStatus::Draft): MonthlyReport
{
    $attributes = ['project_id' => test()->project->id, 'report_template_id' => test()->template->id, 'period' => '2026-11-01'];

    return Activity::withoutRecording(fn (): MonthlyReport => match ($status) {
        MonthlyReportStatus::Draft => MonthlyReport::factory()->create($attributes),
        MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted()->create([...$attributes, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
        MonthlyReportStatus::Validated => MonthlyReport::factory()->validated()->create([...$attributes, 'submitted_by' => test()->owner->id, 'prepared_by' => test()->owner->id]),
    });
}

it('lists only the logframe risks of the proposals the report covers', function () {
    $otherProposal = Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create(['status' => ProposalStatus::Approved]));
    Activity::withoutRecording(fn () => Risk::factory()->onProposalFramework($otherProposal)->create());
    Activity::withoutRecording(fn () => Risk::factory()->create(['project_id' => $this->project->id]));

    $ids = MonthlyReportRisks::for(mrrReport())->map(fn (array $row): int => $row['risk']->id)->all();

    expect($ids)->toBe([$this->risk->id, $this->quietRisk->id]);
});

it('pre-ticks each risk from its last answer once the line is on', function () {
    $report = mrrReport();

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.show', ['monthlyReport' => $report])
        ->assertDontSee('Road closure')
        ->set('updatesRisks', true)
        ->assertSet('riskHappened', [$this->risk->id => true, $this->quietRisk->id => false])
        ->assertSee('Road closure')
        ->assertSee('Staff turnover');

    expect($report->fresh()->updates_risks)->toBeTrue();
});

it('saves a tick on the report and on the risk, and flips back on untick', function () {
    $report = mrrReport();

    $page = Livewire::actingAs($this->owner)->test('pages::monthly-reports.show', ['monthlyReport' => $report])
        ->set('updatesRisks', true)
        ->set("riskHappened.{$this->quietRisk->id}", true);

    expect($this->quietRisk->fresh()->happened)->toBeTrue()
        ->and(MonthlyReportRisk::query()->where(['monthly_report_id' => $report->id, 'risk_id' => $this->quietRisk->id])->value('happened'))->toBeTrue();

    $page->set("riskHappened.{$this->quietRisk->id}", false);

    expect($this->quietRisk->fresh()->happened)->toBeFalse()
        ->and(MonthlyReportRisk::query()->where(['monthly_report_id' => $report->id, 'risk_id' => $this->quietRisk->id])->value('happened'))->toBeFalse();
});

it('refuses a risk of another proposal', function () {
    $foreign = Activity::withoutRecording(fn (): Risk => Risk::factory()->onProposalFramework(Proposal::factory()->create())->create(['happened' => false]));
    $this->actingAs($this->owner);

    expect(fn () => app(SaveMonthlyReportRisks::class)->handle(mrrReport(), MonthlyReportStatus::Draft, true, [$foreign->id => true]))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->happened)->toBeFalse()
        ->and(MonthlyReportRisk::query()->count())->toBe(0);
});

it('refuses ticks on a submitted report', function () {
    $this->actingAs($this->owner);
    $report = mrrReport(MonthlyReportStatus::Submitted);

    expect(fn () => app(SaveMonthlyReportRisks::class)->handle($report, MonthlyReportStatus::Submitted, true, [$this->quietRisk->id => true]))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveMonthlyReportRisks::class)->handle($report, MonthlyReportStatus::Draft, true, [$this->quietRisk->id => true]))
        ->toThrow(ValidationException::class);

    expect($this->quietRisk->fresh()->happened)->toBeFalse();
});

it('refuses ticks from a user who cannot update the report', function () {
    $this->actingAs($this->officer);

    expect(fn () => app(SaveMonthlyReportRisks::class)->handle(mrrReport(), MonthlyReportStatus::Draft, true, [$this->quietRisk->id => true]))
        ->toThrow(AuthorizationException::class);

    expect($this->quietRisk->fresh()->happened)->toBeFalse();
});

it('hides the list when the line is turned off but keeps the saved answers', function () {
    $report = mrrReport();

    Livewire::actingAs($this->owner)->test('pages::monthly-reports.show', ['monthlyReport' => $report])
        ->set('updatesRisks', true)
        ->set("riskHappened.{$this->quietRisk->id}", true)
        ->set('updatesRisks', false)
        ->assertDontSee('Staff turnover');

    expect($report->fresh()->updates_risks)->toBeFalse()
        ->and($report->riskEntries()->count())->toBe(1);
});
