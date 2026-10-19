<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\OverdueScanner;
use App\Notifications\SubjectLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

test('the catalog lists open dated items with their direct people and closes the finished ones', function () {
    $employee = insertHrEmployee();
    $assignee = userWithRole('Managers', $employee);

    // Factories create related records with random dates, so every check
    // below targets the records made here rather than "nothing else".
    $closed = Activity::withoutRecording(function () use ($employee): array {
        $open = Task::factory()->create(['due_date' => '2026-09-30', 'title' => 'Open task']);
        $open->taskAssignees()->delete();
        $open->taskAssignees()->create(['employee_id' => $employee]);
        Task::factory()->create(['due_date' => '2026-09-30', 'status' => TaskStatus::Done, 'title' => 'Done task']);
        Task::factory()->create(['due_date' => '2026-12-31', 'title' => 'Far task']);
        DonorReport::factory()->create(['due_on' => '2026-09-28', 'title' => 'Interim report']);
        Proposal::factory()->create(['planned_date_end' => '2026-09-28', 'name' => 'Ending proposal']);
        TransfersTracking::factory()->create(['scheduled_date' => '2026-09-28', 'payment_amount' => '4321.00']);

        return [
            'milestone' => Milestone::factory()->create(['planned_end_date' => '2026-09-29', 'status' => MilestoneStatus::Cancelled])->id,
            'report' => Report::factory()->reviewed()->create(['planned_date' => '2026-09-29'])->id,
            'project' => Project::factory()->status(ProjectStatus::Closed)->create(['end_date' => '2026-09-28'])->id,
        ];
    });

    $items = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-10-15'));
    $labels = $items->pluck('label')->all();

    expect($labels)->toContain('Open task', 'Interim report')
        ->not->toContain('Done task', 'Far task')
        ->and($items->firstWhere('label', 'Open task')->directUserIds)->toContain($assignee->id)
        ->and($items->firstWhere('label', 'Interim report')->watcherRoles)->toBe(['Financial', 'Public Relations Manager']);

    foreach ($closed as $type => $id) {
        expect($items->where('subjectType', $type)->pluck('subjectId')->all())->not->toContain($id);
    }

    expect($items->where('subjectType', 'proposal')->pluck('label')->all())->toContain('Ending proposal')
        ->and($items->where('subjectType', 'transfer')->pluck('label')->filter(fn (string $label): bool => str_ends_with($label, ' · 2026-09-28'))->count())->toBe(1)
        ->and($items->where('subjectType', 'transfer')->pluck('label')->filter(fn (string $label): bool => str_contains($label, '4,321'))->count())->toBe(0);
});

/*
| Donor reports (T19, contracts §14.3, §15.2 `donor_report.due` / `.overdue`):
| the responsible person hears without funding-agreements.view and is sent
| to My Reporting Duties; when they cannot act, the slot holder of the
| report's type does; with neither, Financial and the PR Manager.
| 2026-11-16 is a Monday.
*/

function donorReportAlert(array $attributes): DonorReport
{
    return Activity::withoutRecording(fn (): DonorReport => DonorReport::factory()->composed()->create(['title' => 'Q3 narrative', ...$attributes]));
}

function donorReportNotifications(int $userId, int $reportId): Collection
{
    return UserNotification::query()->where('notifiable_type', (new User)->getMorphClass())->where('notifiable_id', $userId)->where('subject_type', 'donor_report')->where('subject_id', $reportId)->get();
}

test('a donor report reminder reaches its responsible person without the money permission and leads to My Reporting Duties', function () {
    $employeeId = insertHrEmployee();
    $responsible = userWithRole('Team Member', $employeeId);
    $report = donorReportAlert(['due_on' => '2026-11-18', 'responsible_employee_id' => $employeeId]);

    $item = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-11-30'))->where('subjectType', 'donor_report')->firstWhere('subjectId', $report->id);

    expect($responsible->can('funding-agreements.view'))->toBeFalse()
        ->and($item->module)->toBe('donor-reports')
        ->and($item->eventKey)->toBe('donor_report')
        ->and($item->directUserIds)->toBe([$responsible->id])
        ->and($item->exemptUserIds)->toBe([$responsible->id]);

    $this->travelTo('2026-11-16 09:00');
    app(DueSoonScanner::class)->scan(CarbonImmutable::now());

    $due = donorReportNotifications($responsible->id, $report->id);

    expect($due)->toHaveCount(1)
        ->and($due->first()->data['link'])->toBe(['route' => 'reporting-duties.index'])
        ->and(app(SubjectLinks::class)->urlFor($due->first()))->toBe(route('reporting-duties.index'))
        ->and(DB::table('notification_alert_ledger')->where('subject_type', 'donor_report')->where('subject_id', $report->id)->pluck('stage')->all())
        ->each->toEndWith(':2026-11-18');

    $this->travelTo('2026-11-19 09:00');
    app(OverdueScanner::class)->scan(CarbonImmutable::now());

    expect(donorReportNotifications($responsible->id, $report->id))->toHaveCount(2);
});

test('an overdue donor report also reaches oversight as a copy', function () {
    $employeeId = insertHrEmployee();
    $responsible = userWithRole('Team Member', $employeeId);
    $manager = userWithRole('General Manager');
    $report = donorReportAlert(['due_on' => '2026-11-17', 'responsible_employee_id' => $employeeId]);

    $this->travelTo('2026-11-18 09:00');
    app(OverdueScanner::class)->scan(CarbonImmutable::now());

    expect(donorReportNotifications($responsible->id, $report->id))->toHaveCount(1)
        ->and(donorReportNotifications($manager->id, $report->id))->toHaveCount(1);
});

test('an unreachable responsible hands the reminder to the slot holder, then to the finance watchers', function () {
    $inactiveEmployee = insertHrEmployee(isActive: false);
    userWithRole('Team Member', $inactiveEmployee);

    $department = insertHrDepartment('Fundraising');
    $fundraisingEmployee = insertHrEmployee();
    $position = insertHrPosition($department, 'Projects & Fundraising Manager');
    insertHrContract($fundraisingEmployee, $position);
    $fundraiser = userWithRole('Public Relations Manager', $fundraisingEmployee);
    $financial = userWithRole('Financial');

    $narrative = donorReportAlert(['due_on' => '2026-11-18', 'responsible_employee_id' => $inactiveEmployee]);
    $financialReport = donorReportAlert(['due_on' => '2026-11-18', 'type' => 'financial', 'title' => 'Q3 financial', 'responsible_employee_id' => insertHrEmployee()]);

    $settings = app(NotificationSettings::class);
    $settings->save(['proposal_default_reviewers' => ['meal_manager' => null, 'finance_manager' => null, 'fundraising_manager' => $position, 'ceo' => null]]);

    $items = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-11-30'))->where('subjectType', 'donor_report');

    expect($items->firstWhere('subjectId', $narrative->id)->directUserIds)->toBe([$fundraiser->id])
        ->and($items->firstWhere('subjectId', $narrative->id)->link)->toBe([])
        ->and($items->firstWhere('subjectId', $financialReport->id)->directUserIds)->toBe([]);

    $this->travelTo('2026-11-16 09:00');
    app(DueSoonScanner::class)->scan(CarbonImmutable::now());

    expect(donorReportNotifications($fundraiser->id, $narrative->id))->toHaveCount(1)
        ->and(donorReportNotifications($financial->id, $financialReport->id))->toHaveCount(1)
        ->and(donorReportNotifications($financial->id, $narrative->id))->toHaveCount(0);
});
