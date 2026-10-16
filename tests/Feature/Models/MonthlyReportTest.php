<?php

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\Gender;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineBreakdown;
use App\Models\MonthlyReportLineShare;
use App\Models\MonthlyReportReopenRequest;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\ProjectActivityStatus;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A report with one line, one share and one breakdown, built without
 * recording activity.
 *
 * @return array{report: MonthlyReport, line: MonthlyReportLine, share: MonthlyReportLineShare, breakdown: MonthlyReportLineBreakdown}
 */
function monthlyReportWithLine(): array
{
    return Activity::withoutRecording(function (): array {
        $report = MonthlyReport::factory()->create();
        $line = MonthlyReportLine::factory()->for($report)->create();
        $share = MonthlyReportLineShare::factory()->for($line, 'line')->create();
        $breakdown = MonthlyReportLineBreakdown::factory()->for($line, 'line')->for($share, 'share')->create();

        return ['report' => $report, 'line' => $line, 'share' => $share, 'breakdown' => $breakdown];
    });
}

test('a monthly report casts its period, status and people', function () {
    $report = Activity::withoutRecording(fn () => MonthlyReport::factory()->validated()->create(['period' => '2026-10-01']));

    $fresh = MonthlyReport::query()->findOrFail($report->id);

    expect($fresh->period)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->period->toDateString())->toBe('2026-10-01')
        ->and($fresh->status)->toBe(MonthlyReportStatus::Validated)
        ->and($fresh->submitted_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->validated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->submitted_by)->toBeInt()
        ->and($fresh->validated_by)->toBeInt()
        ->and($fresh->seen_by_meal_at)->toBeNull();
});

test('the slug is the project slug and the month', function () {
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Water Network Expansion']));
    $report = Activity::withoutRecording(fn () => MonthlyReport::factory()->for($project)->create(['period' => '2026-10-01']));

    expect($report->slug)->toBe($project->slug.'-2026-10')
        ->and($report->getRouteKeyName())->toBe('slug');
});

test('a new monthly report defaults to draft at the database', function () {
    $projectId = Activity::withoutRecording(fn () => Project::factory()->create()->id);

    $id = DB::table('monthly_reports')->insertGetId([
        'slug' => 'raw-2026-10', 'project_id' => $projectId, 'period' => '2026-10-01',
    ]);

    expect(DB::table('monthly_reports')->find($id)->status)->toBe('draft');
});

test('a project has one monthly report per month', function () {
    $report = Activity::withoutRecording(fn () => MonthlyReport::factory()->create(['period' => '2026-10-01']));

    expect(fn () => Activity::withoutRecording(fn () => MonthlyReport::factory()->create([
        'project_id' => $report->project_id, 'period' => '2026-10-01',
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('a project with monthly reports cannot be deleted', function () {
    $report = Activity::withoutRecording(fn () => MonthlyReport::factory()->create());

    expect(fn () => DB::table('projects')->where('id', $report->project_id)->delete())->toThrow(QueryException::class);
});

test('a user named on a monthly report cannot be deleted', function () {
    $report = Activity::withoutRecording(fn () => MonthlyReport::factory()->submitted()->create());

    expect(fn () => DB::table('users')->where('id', $report->submitted_by)->delete())->toThrow(QueryException::class);
});

test('lines, shares and breakdowns belong to their report and cascade with it', function () {
    ['report' => $report, 'line' => $line, 'share' => $share, 'breakdown' => $breakdown] = monthlyReportWithLine();

    $loaded = MonthlyReport::query()->with('lines.shares.breakdowns', 'lines.breakdowns', 'lines.libraryIndicator', 'lines.shares.planIndicator')->findOrFail($report->id);

    expect($loaded->lines->sole()->is($line))->toBeTrue()
        ->and($loaded->lines->sole()->libraryIndicator)->toBeInstanceOf(LibraryIndicator::class)
        ->and($loaded->lines->sole()->entered_by)->toBe(IndicatorCollector::ProjectTeam)
        ->and($loaded->lines->sole()->shares->sole()->is($share))->toBeTrue()
        ->and($loaded->lines->sole()->shares->sole()->planIndicator)->toBeInstanceOf(PlanIndicator::class)
        ->and($loaded->lines->sole()->shares->sole()->breakdowns->sole()->is($breakdown))->toBeTrue()
        ->and($loaded->lines->sole()->breakdowns->sole()->gender)->toBe(Gender::Male);

    DB::table('monthly_reports')->where('id', $report->id)->delete();

    expect(DB::table('monthly_report_lines')->count())->toBe(0)
        ->and(DB::table('monthly_report_line_shares')->count())->toBe(0)
        ->and(DB::table('monthly_report_line_breakdowns')->count())->toBe(0);
});

test('a line and a share default to measured at the database', function () {
    ['line' => $line] = monthlyReportWithLine();
    $planIndicatorId = Activity::withoutRecording(fn () => PlanIndicator::factory()->create()->id);

    $shareId = DB::table('monthly_report_line_shares')->insertGetId([
        'monthly_report_line_id' => $line->id, 'plan_indicator_id' => $planIndicatorId,
    ]);

    expect(DB::table('monthly_report_lines')->find($line->id)->not_measured)->toBe(0)
        ->and(DB::table('monthly_report_line_shares')->find($shareId)->not_measured)->toBe(0);
});

test('a report has one line per library indicator and a line one share per target', function () {
    ['line' => $line, 'share' => $share] = monthlyReportWithLine();

    expect(fn () => DB::table('monthly_report_lines')->insert([
        'monthly_report_id' => $line->monthly_report_id, 'library_indicator_id' => $line->library_indicator_id, 'entered_by' => 'project_team',
    ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('monthly_report_line_shares')->insert([
            'monthly_report_line_id' => $line->id, 'plan_indicator_id' => $share->plan_indicator_id,
        ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a library indicator or a target used by a line cannot be deleted', function () {
    ['line' => $line, 'share' => $share] = monthlyReportWithLine();

    expect(fn () => DB::table('library_indicators')->where('id', $line->library_indicator_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('plan_indicators')->where('id', $share->plan_indicator_id)->delete())->toThrow(QueryException::class);
});

test('a reopen request defaults to pending and keeps its report from being deleted', function () {
    $report = Activity::withoutRecording(fn () => MonthlyReport::factory()->validated()->create());
    $requesterId = Activity::withoutRecording(fn () => User::factory()->create()->id);

    $id = DB::table('monthly_report_reopen_requests')->insertGetId([
        'monthly_report_id' => $report->id, 'requested_by' => $requesterId, 'reason' => 'A figure was typed in the wrong month.',
    ]);

    $request = MonthlyReportReopenRequest::query()->with('monthlyReport', 'requester')->findOrFail($id);

    expect(DB::table('monthly_report_reopen_requests')->find($id)->status)->toBe('pending')
        ->and($request->status)->toBe(ReopenRequestStatus::Pending)
        ->and($request->monthlyReport->is($report))->toBeTrue()
        ->and($request->requester->id)->toBe($requesterId)
        ->and(fn () => DB::table('monthly_reports')->where('id', $report->id)->delete())->toThrow(QueryException::class);
});

test('an activity entry belongs to its report, one per activity, and keeps its activity', function () {
    $entry = Activity::withoutRecording(fn () => MonthlyReportActivityEntry::factory()->create([
        'status' => ActivityStatus::Started, 'started_on' => '2026-10-05',
    ]));

    $fresh = MonthlyReportActivityEntry::query()->with('monthlyReport', 'activity')->findOrFail($entry->id);

    expect($fresh->status)->toBe(ActivityStatus::Started)
        ->and($fresh->started_on->toDateString())->toBe('2026-10-05')
        ->and($fresh->activity)->toBeInstanceOf(Result::class)
        ->and(fn () => DB::table('monthly_report_activity_entries')->insert([
            'monthly_report_id' => $entry->monthly_report_id, 'result_id' => $entry->result_id,
        ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('results')->where('id', $entry->result_id)->delete())->toThrow(QueryException::class);
});

test('a project activity status is one per project and activity', function () {
    $status = Activity::withoutRecording(fn () => ProjectActivityStatus::factory()->create(['status' => ActivityStatus::Ongoing]));

    $fresh = ProjectActivityStatus::query()->with('project', 'activity', 'monthlyReport')->findOrFail($status->id);

    expect($fresh->status)->toBe(ActivityStatus::Ongoing)
        ->and($fresh->monthlyReport)->toBeInstanceOf(MonthlyReport::class)
        ->and(fn () => DB::table('project_activity_statuses')->insert([
            'project_id' => $status->project_id, 'result_id' => $status->result_id, 'status' => 'started', 'monthly_report_id' => $status->monthly_report_id,
        ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a mirror report points at its monthly report and share, carries a ratio and cascades with the report', function () {
    ['report' => $monthly, 'share' => $share] = monthlyReportWithLine();

    $mirror = Activity::withoutRecording(fn () => Report::factory()->create([
        'plan_indicator_id' => $share->plan_indicator_id,
        'monthly_report_id' => $monthly->id,
        'monthly_report_line_share_id' => $share->id,
        'value' => null,
        'numerator' => '36.00',
        'denominator' => '180.00',
    ]));

    $fresh = Report::query()->with('monthlyReport', 'lineShare')->findOrFail($mirror->id);

    expect(DB::table('reports')->find($mirror->id)->value)->toBeNull()
        ->and($fresh->value)->toBeNull()
        ->and($fresh->numerator)->toBe('36.00')
        ->and($fresh->denominator)->toBe('180.00')
        ->and($fresh->isMirror())->toBeTrue()
        ->and($fresh->monthlyReport->is($monthly))->toBeTrue()
        ->and($fresh->lineShare->is($share))->toBeTrue()
        ->and(fn () => DB::table('reports')->insert([
            'slug' => 'second-mirror', 'plan_indicator_id' => $share->plan_indicator_id, 'user_id' => $mirror->user_id,
            'planned_date' => '2026-10-31', 'monthly_report_line_share_id' => $share->id,
        ]))->toThrow(UniqueConstraintViolationException::class);

    DB::table('monthly_reports')->where('id', $monthly->id)->delete();

    expect(DB::table('reports')->find($mirror->id))->toBeNull();
});

test('a generic report is not a mirror', function () {
    $report = Activity::withoutRecording(fn () => Report::factory()->create());

    expect(Report::query()->findOrFail($report->id)->isMirror())->toBeFalse();
});

test('each monthly report status maps to one mirror status and only a draft is editable', function () {
    expect(MonthlyReportStatus::Draft->mirrorStatus())->toBe(ReportStatus::Draft)
        ->and(MonthlyReportStatus::Submitted->mirrorStatus())->toBe(ReportStatus::Reviewed)
        ->and(MonthlyReportStatus::Validated->mirrorStatus())->toBe(ReportStatus::Approved)
        ->and(MonthlyReportStatus::Draft->isEditable())->toBeTrue()
        ->and(MonthlyReportStatus::Submitted->isEditable())->toBeFalse()
        ->and(MonthlyReportStatus::Validated->isEditable())->toBeFalse();
});

test('an event accepts only not started, conducted and cancelled; a continuous activity cannot be conducted', function () {
    expect(ActivityStatus::allowedFor(ActivityKind::Event))->toBe([
        ActivityStatus::NotStarted, ActivityStatus::Cancelled, ActivityStatus::Conducted,
    ])
        ->and(ActivityStatus::allowedFor(ActivityKind::Continuous))->not->toContain(ActivityStatus::Conducted)
        ->and(ActivityStatus::Completed->isFinal())->toBeTrue()
        ->and(ActivityStatus::Cancelled->isFinal())->toBeTrue()
        ->and(ActivityStatus::Conducted->isFinal())->toBeTrue()
        ->and(ActivityStatus::Ongoing->isFinal())->toBeFalse();
});
