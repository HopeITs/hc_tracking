<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\DeadlineItem;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\OverdueScanner;
use App\Notifications\EventCatalog;
use App\Notifications\Recipients\OversightRecipients;
use App\Support\MonthlyReportNames;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Monthly report deadlines (contracts §13, §13.1 item 2, §15.2): one item per
| started, required, unsubmitted month, due the configured working days
| after the month's end (default 5). 2026-11-16 is a Monday.
*/

beforeEach(function () {
    $this->travelTo('2026-11-16 09:00:00');
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->owner = userWithRole('Public Relations', $this->ownerEmployee);
    $this->project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'program_id' => Program::factory()->create()->id,
        'user_id' => $this->ownerEmployee,
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]));
});

function mraAlertReport(string $month, bool $withLine = true, MonthlyReportStatus $status = MonthlyReportStatus::Draft): MonthlyReport
{
    return Activity::withoutRecording(function () use ($month, $withLine, $status): MonthlyReport {
        $report = MonthlyReport::factory()->create(['project_id' => test()->project->id, 'period' => $month, 'status' => $status]);

        if ($withLine) {
            MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id]);
        }

        return $report;
    });
}

/**
 * @return list<DeadlineItem>
 */
function mraAlertItems(string $until = '2027-06-30'): array
{
    return array_values(app(DeadlineCatalog::class)->items(CarbonImmutable::parse($until))->where('subjectType', 'monthly_report')->all());
}

test('one item per started, required, unsubmitted month, due five working days after the month', function () {
    $october = mraAlertReport('2026-10-01');
    $november = mraAlertReport('2026-11-01');
    mraAlertReport('2026-12-01');                                              // not started
    $lineless = Activity::withoutRecording(fn () => MonthlyReport::factory()->create(['project_id' => Project::factory()->active()->create()->id, 'period' => '2026-10-01']));
    $submitted = Activity::withoutRecording(fn () => tap(mraAlertReport('2026-09-01'))->forceFill(['status' => MonthlyReportStatus::Submitted])->save());

    $items = collect(mraAlertItems())->keyBy('subjectId');

    expect($items->keys()->all())->toEqualCanonicalizing([$october->id, $november->id])
        ->and($items[$october->id]->dueDate->toDateString())->toBe('2026-11-06')  // Sat 31 Oct + 5 working days
        ->and($items[$november->id]->dueDate->toDateString())->toBe('2026-12-07') // Mon 30 Nov + 5 working days
        ->and($items[$october->id]->eventKey)->toBe('monthly_report')
        ->and($items[$october->id]->module)->toBe('monthly-reports')
        ->and($items[$october->id]->directUserIds)->toBe([$this->owner->id])
        ->and($items[$october->id]->projectId)->toBe($this->project->id)
        ->and($items->has($lineless->id))->toBeFalse();
});

test('the due date follows the Notification Rules setting and the dueOnOrBefore window', function () {
    mraAlertReport('2026-10-01');
    app(NotificationSettings::class)->save(['monthly_report_due_working_days' => 2]);

    expect(mraAlertItems()[0]->dueDate->toDateString())->toBe('2026-11-03')
        ->and(mraAlertItems('2026-11-02'))->toBe([]);
});

test('an overdue month tells the owner and reaches MEAL and General Management oversight', function () {
    $meal = userWithRole('MEAL', insertHrEmployee());
    $gm = userWithRole('General Manager', insertHrEmployee());
    mraAlertReport('2026-10-01');

    app(OverdueScanner::class)->scan(CarbonImmutable::now());

    expect(collect(['owner' => $this->owner, 'meal' => $meal, 'gm' => $gm])->map(fn (User $user): int => UserNotification::query()->forUser($user)->count())->all())
        ->toBe(['owner' => 1, 'meal' => 1, 'gm' => 1]);
});

test('the monthly report catalog keys reach MEAL and General Management', function () {
    $meal = userWithRole('MEAL', insertHrEmployee());
    $gm = userWithRole('General Manager', insertHrEmployee());

    foreach (['monthly_reports.generated', 'monthly_report.due', 'monthly_report.overdue', 'monthly_report.submitted', 'monthly_report.validated', 'monthly_report.returned', 'monthly_report.reopen_requested', 'monthly_report.reopen_decided', 'monthly_report.reopen_escalated'] as $key) {
        expect(EventCatalog::has($key))->toBeTrue()
            ->and(app(OversightRecipients::class)->userIdsFor($key, $this->project->id))->toContain($meal->id, $gm->id);
    }
});

test('mirrors leave the generic report deadlines to the monthly report', function () {
    $report = mraAlertReport('2026-10-01');
    Activity::withoutRecording(fn () => Report::factory()->create(['monthly_report_id' => $report->id, 'planned_date' => '2026-10-31', 'user_id' => $this->owner->id]));

    expect(app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2027-06-30'))->where('subjectType', 'report')->count())->toBe(0);
});

/*
| Y-03 slice 3 (D-76, D-84): the alert of a month goes to the template's
| preparers, the responsibles of its rows at its place, and names its
| proposals and place; the legacy project only scopes oversight.
*/

/**
 * A Y-02 template at Aleppo's Zone Center in a new program, carried by a
 * new proposal whose row there names the responsible, with a required
 * October draft.
 *
 * @return array{template: ReportTemplate, report: MonthlyReport, proposal: Proposal}
 */
function mraPlacedReport(ReportPlace $place, string $proposalName, int $responsibleEmployeeId): array
{
    return Activity::withoutRecording(function () use ($place, $proposalName, $responsibleEmployeeId): array {
        $proposal = Proposal::factory()->create(['name' => $proposalName, 'planned_date_begin' => '2026-10-01', 'planned_date_end' => '2026-12-31']);
        $row = ProposalLocation::factory()->create(['proposal_id' => $proposal->id, 'city_id' => $place->cityId, 'program_id' => $place->programId, 'responsible_employee_id' => $responsibleEmployeeId]);
        $row->centers()->create(['center_id' => $place->centerId]);
        $template = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
        $report = MonthlyReport::factory()->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => '2026-10-01']);
        MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id]);

        return ['template' => $template, 'report' => $report, 'proposal' => $proposal];
    });
}

test('a month\'s alert goes to the row responsible and the preparer, not to the legacy project owner', function () {
    $report = mraAlertReport('2026-10-01');
    $responsibleEmployee = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Responsible');
    $responsible = userWithRole('Managers', $responsibleEmployee);
    $preparer = Activity::withoutRecording(fn (): User => User::factory()->create());
    Activity::withoutRecording(fn () => $report->forceFill(['prepared_by' => $preparer->id])->save());
    ProposalLocation::query()->whereIn('proposal_id', $report->template->proposals()->pluck('proposals.id'))->update(['responsible_employee_id' => $responsibleEmployee]);

    $item = mraAlertItems()[0];

    expect($item->directUserIds)->toEqualCanonicalizing([$responsible->id, $preparer->id])
        ->and($item->directUserIds)->not->toContain($this->owner->id)
        ->and($item->projectId)->toBe($this->project->id);
});

test('two templates of one place give two alerts told apart by their proposals', function () {
    $city = insertHrCity('Aleppo');
    $program = Activity::withoutRecording(fn (): Program => Program::factory()->create());
    $place = new ReportPlace($city, $program->id, insertHrCenter($city, 'Zone Center'), false);
    $education = mraPlacedReport($place, 'Education for All', $this->ownerEmployee);
    $protection = mraPlacedReport($place, 'Child Protection', $this->ownerEmployee);

    $items = collect(mraAlertItems())->keyBy('subjectId');

    expect($items[$education['report']->id]->label)->toBe(app(MonthlyReportNames::class)->label($education['template'], CarbonImmutable::parse('2026-10-01')))
        ->and($items[$education['report']->id]->label)->toStartWith('Education for All · Aleppo')
        ->and($items[$protection['report']->id]->label)->toStartWith('Child Protection · Aleppo')
        ->and($items[$education['report']->id]->label)->toEndWith('Zone Center · 2026-10')
        ->and($items[$education['report']->id]->directUserIds)->toBe([$this->owner->id])
        ->and($items[$protection['report']->id]->directUserIds)->toBe([$this->owner->id]);
});

test('the monthly report items take a fixed number of queries whatever the number of templates', function () {
    $city = insertHrCity('Aleppo');
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        mraAlertItems();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $place = fn (): ReportPlace => new ReportPlace($city, Activity::withoutRecording(fn (): int => Program::factory()->create()->id), insertHrCenter($city, 'Zone Center'), false);

    mraPlacedReport($place(), 'First', $this->ownerEmployee);
    $count();
    $one = $count();

    mraPlacedReport($place(), 'Second', $this->ownerEmployee);
    mraPlacedReport($place(), 'Third', $this->ownerEmployee);
    $count();

    expect($count())->toBe($one);
});
