<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\EscalationScanner;
use App\Notifications\Alerts\OverdueScanner;
use Carbon\CarbonImmutable;

function overdueTaskFor(User $assignee, string $due, ?int $programId = null): Task
{
    return Activity::withoutRecording(function () use ($assignee, $due, $programId): Task {
        $project = Project::factory()->create(['end_date' => '2027-12-31', ...($programId === null ? [] : ['program_id' => $programId])]);
        $milestone = Milestone::factory()->create(['project_id' => $project->id, 'planned_end_date' => '2027-12-31']);
        $task = Task::factory()->create(['due_date' => $due, 'title' => 'Plan', 'source_type' => 'milestone', 'source_id' => $milestone->id]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });
}

function runScanner(string $class, string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app($class)->scan(CarbonImmutable::now());
}

test('overdue fires on day one then every three working days', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    overdueTaskFor($assignee, '2026-09-18'); // Friday

    expect(runScanner(OverdueScanner::class, '2026-09-21 09:00'))->toBe(1) // day 1 (Monday)
        ->and(runScanner(OverdueScanner::class, '2026-09-23 09:00'))->toBe(0) // day 3
        ->and(runScanner(OverdueScanner::class, '2026-09-24 09:00'))->toBe(1) // day 4
        ->and(runScanner(OverdueScanner::class, '2026-09-27 09:00'))->toBe(0) // Sunday
        ->and(runScanner(OverdueScanner::class, '2026-09-29 09:00'))->toBe(1); // day 7

    expect(UserNotification::query()->forUser($assignee)->pluck('priority')->unique()->all())->toBe([NotificationPriority::Important]);
});

test('an item due on a weekend starts its overdue count on the next working day', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    overdueTaskFor($assignee, '2026-09-26'); // Saturday

    expect(runScanner(OverdueScanner::class, '2026-09-28 09:00'))->toBe(1);
    expect(sentencesFor($assignee)[0])->toContain('1 working day');
});

test('escalation reaches the program manager at five and the top roles at ten working days', function () {
    $managerEmployee = insertHrEmployee();
    $manager = userWithRole('Managers', $managerEmployee);
    $generalManager = userWithRole('General Manager');
    $program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $managerEmployee]));
    overdueTaskFor(userWithRole('Managers', insertHrEmployee()), '2026-09-11', $program->id); // Friday

    expect(runScanner(EscalationScanner::class, '2026-09-17 09:00'))->toBe(0) // day 4
        ->and(runScanner(EscalationScanner::class, '2026-09-18 09:00'))->toBe(1) // day 5 → manager
        ->and(runScanner(EscalationScanner::class, '2026-09-25 09:00'))->toBeGreaterThanOrEqual(1); // day 10 → top

    expect(UserNotification::query()->forUser($manager)->sole()->priority)->toBe(NotificationPriority::Critical)
        ->and(UserNotification::query()->forUser($generalManager)->sole()->priority)->toBe(NotificationPriority::Critical);
});
