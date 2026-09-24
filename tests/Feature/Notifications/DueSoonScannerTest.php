<?php

use App\Enums\NotificationPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\NotificationSettings;
use Carbon\CarbonImmutable;

// 2026-09-21 is a Monday; working days default Monday–Friday.
function dueTask(string $due, ?User $assignee = null, TaskStatus $status = TaskStatus::Todo): Task
{
    return Activity::withoutRecording(function () use ($due, $assignee, $status): Task {
        $task = Task::factory()->create(['due_date' => $due, 'status' => $status, 'title' => 'Plan']);

        if ($assignee !== null) {
            $task->taskAssignees()->delete();
            $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);
        }

        return $task;
    });
}

function scanDueSoon(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DueSoonScanner::class)->scan(CarbonImmutable::now());
}

test('each stage fires once, counted in working days', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-30', $assignee); // Wednesday

    expect(scanDueSoon('2026-09-21 09:00'))->toBe(1)   // 7 working days
        ->and(scanDueSoon('2026-09-21 09:15'))->toBe(0) // same stage, deduplicated
        ->and(scanDueSoon('2026-09-24 09:00'))->toBe(0) // 4 left: no stage
        ->and(scanDueSoon('2026-09-25 09:00'))->toBe(1) // 3 left (Mon, Tue, Wed)
        ->and(scanDueSoon('2026-09-29 09:00'))->toBe(1) // 1 left
        ->and(scanDueSoon('2026-09-30 09:00'))->toBe(1); // today

    $priorities = UserNotification::query()->forUser($assignee)->oldest()->pluck('priority')->all();

    expect($priorities)->toBe([NotificationPriority::Info, NotificationPriority::Info, NotificationPriority::Info, NotificationPriority::Important])
        ->and(sentencesFor($assignee)[3])->toBe('“Plan” is due today');
});

test('an item due on a weekend is warned on the last working day before it', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-26', $assignee); // Saturday

    expect(scanDueSoon('2026-09-25 09:00'))->toBe(1);

    expect(sentencesFor($assignee)[0])->toContain('due on Saturday');
});

test('no deadline alerts on non-working days', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-28', $assignee);

    expect(scanDueSoon('2026-09-27 09:00'))->toBe(0); // Sunday
});

test('a custom working week moves the warning', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4]]); // Sunday–Thursday
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-25', $assignee); // Friday is now a weekend

    expect(scanDueSoon('2026-09-24 09:00'))->toBe(1)
        ->and(sentencesFor($assignee)[0])->toContain('due on Friday');
});

test('finished items stop alerting and a moved due date re-arms the stages', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $task = dueTask('2026-09-22', $assignee);

    expect(scanDueSoon('2026-09-21 09:00'))->toBe(1);

    Activity::withoutRecording(fn () => $task->update(['due_date' => '2026-09-23']));
    expect(scanDueSoon('2026-09-22 09:00'))->toBe(1);

    Activity::withoutRecording(fn () => $task->update(['status' => TaskStatus::Done]));
    expect(scanDueSoon('2026-09-23 09:00'))->toBe(0);
});

test('a missed run still fires the current stage once', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-23', $assignee); // created with 2 working days left

    expect(scanDueSoon('2026-09-21 09:00'))->toBe(1)
        ->and(scanDueSoon('2026-09-21 18:00'))->toBe(0);
});

test('the day boundary follows the app timezone', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-25', $assignee);

    scanDueSoon('2026-09-24 23:59');
    scanDueSoon('2026-09-25 00:01');

    expect(sentencesFor($assignee))->toHaveCount(2)
        ->and(sentencesFor($assignee)[1])->toBe('“Plan” is due today');
});
