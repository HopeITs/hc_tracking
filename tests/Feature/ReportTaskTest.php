<?php

use App\Actions\DonorReports\SaveDonorReportSnapshot;
use App\Actions\FundingAgreements\DeleteDonorReport;
use App\Actions\FundingAgreements\MarkDonorReportSubmitted;
use App\Actions\Tasks\SyncReportTask;
use App\Enums\DonorReportType;
use App\Enums\ReportAudience;
use App\Enums\ReportingPeriod;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Task;
use Livewire\Livewire;

/*
| A donor report with a responsible becomes one report task for them
| (contracts §12.2, D-64); submitting the report closes it. The task never
| submits the report.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
    $this->responsibleEmployeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Owner');
    $this->actor = userWithRole('Public Relations Manager');
    $this->actingAs($this->actor);
});

function reportForTask(?int $responsibleEmployeeId, array $attributes = []): DonorReport
{
    return Activity::withoutRecording(fn (): DonorReport => DonorReport::factory()->composed()->create([
        'responsible_employee_id' => $responsibleEmployeeId,
        'due_on' => '2026-11-20',
        'audience' => ReportAudience::Internal->value,
        ...$attributes,
    ]));
}

function syncReportTask(DonorReport $report): ?Task
{
    return app(SyncReportTask::class)->handle($report->fresh());
}

function reportTasks(DonorReport $report)
{
    return Task::query()->withoutGlobalScopes()->where('reportable_type', 'donor_report')->where('reportable_id', $report->id);
}

test('assigning a responsible creates one report task for them', function () {
    $report = reportForTask($this->responsibleEmployeeId);

    $task = syncReportTask($report);

    expect($task)->not->toBeNull()
        ->and($task->kind)->toBe(TaskKind::Report)
        ->and($task->source_type)->toBe('proposal')
        ->and($task->source_id)->toBe($report->donorProposal->proposal_id)
        ->and($task->reportable_type)->toBe('donor_report')
        ->and($task->reportable_id)->toBe($report->id)
        ->and($task->due_date->toDateString())->toBe('2026-11-20')
        ->and($task->created_by)->toBe($this->actor->id)
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->title)->toContain($report->donorProposal->donor->name)->toContain('2026-01')->toContain('2026-03')
        ->and($task->taskAssignees()->pluck('employee_id')->all())->toBe([$this->responsibleEmployeeId]);
});

test('a report without a responsible creates nothing', function () {
    $report = reportForTask(null);

    expect(syncReportTask($report))->toBeNull()
        ->and(reportTasks($report)->count())->toBe(0);
});

test('syncing again for the same person creates nothing and keeps the title', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $first = syncReportTask($report);

    $report->update(['title' => 'Renamed later']);
    $second = syncReportTask($report);

    expect(reportTasks($report)->count())->toBe(1)
        ->and($second->is($first))->toBeTrue()
        ->and($second->fresh()->title)->toBe($first->title)
        ->and($second->taskAssignees()->count())->toBe(1);
});

test('a change of responsible moves the single assignee', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);
    $newEmployeeId = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Other');

    $report->update(['responsible_employee_id' => $newEmployeeId]);
    syncReportTask($report);

    expect(reportTasks($report)->count())->toBe(1)
        ->and($task->fresh()->taskAssignees()->pluck('employee_id')->all())->toBe([$newEmployeeId]);
});

test('submitting the report completes the task', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);

    app(MarkDonorReportSubmitted::class)->handle($report->fresh(), '2026-11-14', [], $this->actor);

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->completed_date->toDateString())->toBe('2026-11-15')
        ->and(reportTasks($report)->count())->toBe(1);
});

test('reopening a submitted report reopens its task', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);
    $report->update(['submitted_on' => '2026-11-14']);
    syncReportTask($report);
    expect($task->fresh()->status)->toBe(TaskStatus::Done);

    $report->update(['submitted_on' => null]);
    syncReportTask($report);

    expect(reportTasks($report)->count())->toBe(1)
        ->and($task->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($task->fresh()->completed_date)->toBeNull();
});

test('a task closed by hand leaves the report unsubmitted; the report still owes its task when synced again', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);

    $task->update(['status' => TaskStatus::Done, 'completed_date' => '2026-11-15']);

    expect($report->fresh()->isSubmitted())->toBeFalse();

    syncReportTask($report);

    expect(reportTasks($report)->count())->toBe(1)
        ->and($task->fresh()->status)->toBe(TaskStatus::Todo);
});

test('composing a report with a responsible creates its task', function () {
    $report = app(SaveDonorReportSnapshot::class)::insertNextVersion($report = reportForTask(null)->donor_proposal_id, [
        'title' => 'Q1 report',
        'type' => DonorReportType::Narrative,
        'audience' => ReportAudience::Internal,
        'period_type' => ReportingPeriod::Quarterly,
        'period_start' => '2026-04-01',
        'period_end' => '2026-06-30',
        'due_on' => '2026-11-30',
        'snapshot' => ['schema_version' => 1],
        'responsible_employee_id' => $this->responsibleEmployeeId,
    ]);

    expect(reportTasks($report)->count())->toBe(1);
});

test('a newer version takes the open task of the version it supersedes', function () {
    $old = reportForTask($this->responsibleEmployeeId);
    syncReportTask($old);
    $new = reportForTask($this->responsibleEmployeeId, ['donor_proposal_id' => $old->donor_proposal_id, 'type' => $old->type->value, 'version' => 2]);

    syncReportTask($new);

    expect(reportTasks($old)->count())->toBe(0)
        ->and(reportTasks($new)->count())->toBe(1);
});

test('deleting a report removes its open task', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    syncReportTask($report);

    app(DeleteDonorReport::class)->handle($report->fresh());

    expect(Task::query()->withoutGlobalScopes()->where('reportable_type', 'donor_report')->count())->toBe(0);
});

test('the proposal page lists the report task', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);
    $proposal = $report->donorProposal->proposal;

    $component = Livewire::actingAs($this->actor)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan');

    expect($component->instance()->planGroups['other']->pluck('id')->all())->toContain($task->id);
});

test('the desk Tasks panel marks the row with a Report chip and links the title to the report', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);
    $me = userWithRole('Team Member', $this->responsibleEmployeeId);

    Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->assertSeeText(TaskKind::Report->label())
        ->assertSeeHtml('href="'.route('donor-reports.show', $report->id).'"');

    expect($task->fresh()->kind)->toBe(TaskKind::Report);
});

test('the task page explains that finishing the task does not submit the report', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    $task = syncReportTask($report);

    Livewire::actingAs($this->actor)->test('pages::tasks.show', ['task' => $task])
        ->assertSeeText(__('tasks.report_task_helper'))
        ->assertSeeHtml('href="'.route('donor-reports.show', $report->id).'"');
});

test('report tasks cannot be created as department tasks', function () {
    expect(in_array(TaskKind::Report->value, TaskKind::departmentValues(), true))->toBeFalse()
        ->and(TaskKind::departmentValues())->toContain('general', 'media_deliverable', 'financial');
});

test('the Tasks register marks the row with a Report chip and links the title to the report', function () {
    $report = reportForTask($this->responsibleEmployeeId);
    syncReportTask($report);
    $me = userWithRole('Team Member', $this->responsibleEmployeeId);

    Livewire::actingAs($me)->test('pages::tasks.index')
        ->assertSeeHtml('data-test="report-task-chip"')
        ->assertSeeHtml('href="'.route('donor-reports.show', $report->id).'"');
});
