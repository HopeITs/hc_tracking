<?php

use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\UserNotification;
use App\Notifications\Alerts\StalledScanner;
use Carbon\CarbonImmutable;

function scanStalled(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(StalledScanner::class)->scan(CarbonImmutable::now());
}

test('a task in progress for seven working days without a status change stalls once', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $task = Activity::withoutRecording(function () use ($assignee) {
        $task = Task::factory()->create(['title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00')); // Monday
    $task->update(['status' => TaskStatus::InProgress]);
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00'));
    $task->update(['title' => 'Plan v2']); // not a status change

    expect(scanStalled('2026-09-22 09:00'))->toBe(0) // 6 working days
        ->and(scanStalled('2026-09-23 09:00'))->toBe(1) // 7
        ->and(scanStalled('2026-09-24 09:00'))->toBe(0);

    expect(UserNotification::query()->forUser($assignee)->where('kind', 'alert')->sole()->data['key'])->toBe('notifications.alerts.stalled.task');
});

test('a status change re-arms the stall', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $task = Activity::withoutRecording(function () use ($assignee) {
        $task = Task::factory()->create(['status' => TaskStatus::InProgress]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $task->update(['status' => TaskStatus::UnderReview]);

    expect(scanStalled('2026-09-17 09:00'))->toBe(1); // 3 working days under review

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00'));
    $task->update(['status' => TaskStatus::InProgress]);
    $task->update(['status' => TaskStatus::UnderReview]);

    expect(scanStalled('2026-09-18 09:00'))->toBe(0)
        ->and(scanStalled('2026-09-22 09:00'))->toBe(1);
});

test('a draft report stalls for its author and meal; nothing fires on a weekend', function () {
    $author = userWithRole('Public Relations');
    $meal = userWithRole('MEAL');
    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    Report::factory()->create(['user_id' => $author->id, 'status' => ReportStatus::Draft]);

    expect(scanStalled('2026-09-26 09:00'))->toBe(0) // Saturday
        ->and(scanStalled('2026-09-23 09:00'))->toBeGreaterThanOrEqual(2);

    expect(UserNotification::query()->forUser($meal)->where('kind', 'alert')->count())->toBe(1);
});

test('a late tranche is never reported as stalled, sent or not: the overdue chain covers it', function () {
    $financial = userWithRole('Financial');
    Activity::withoutRecording(function (): void {
        TransfersTracking::factory()->create(['scheduled_date' => '2026-08-03']);
        TransfersTracking::factory()->create(['scheduled_date' => '2026-08-03', 'transfer_date' => '2026-08-04']);
    });

    expect(collect(app(StalledScanner::class)->stalledSubjects(CarbonImmutable::parse('2026-09-23')))->pluck('type'))->not->toContain('transfer');

    scanStalled('2026-09-23 09:00');

    expect(UserNotification::query()->forUser($financial)->where('data', 'like', '%stalled%')->count())->toBe(0);
});
