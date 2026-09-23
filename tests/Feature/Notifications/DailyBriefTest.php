<?php

use App\Models\Activity;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\DailyBriefScanner;
use Carbon\CarbonImmutable;

test('the daily brief counts my due, overdue and new assignments and goes once a day', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    Activity::withoutRecording(function () use ($me): void {
        foreach (['2026-09-23', '2026-09-18'] as $due) {
            $task = Task::factory()->create(['due_date' => $due, 'created_by' => $me->id]);
            $task->taskAssignees()->delete();
            $task->taskAssignees()->create(['employee_id' => $me->employee_id]);
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-23 07:30'));

    expect(app(DailyBriefScanner::class)->scan(CarbonImmutable::now()))->toBe(1)
        ->and(app(DailyBriefScanner::class)->scan(CarbonImmutable::now()))->toBe(0);

    $brief = UserNotification::query()->forUser($me)->sole();

    expect($brief->kind->value)->toBe('digest')
        ->and(sentencesFor($me)[0])->toBe('Your day: 1 due today, 1 overdue, 0 stalled, 0 new assignments');
});

test('nobody gets an empty brief and nothing goes on a weekend', function () {
    $me = userWithRole('Managers', insertHrEmployee());

    expect(app(DailyBriefScanner::class)->scan(CarbonImmutable::parse('2026-09-23 07:30')))->toBe(0)
        ->and(app(DailyBriefScanner::class)->scan(CarbonImmutable::parse('2026-09-26 07:30')))->toBe(0)
        ->and(UserNotification::query()->forUser($me)->count())->toBe(0);
});
