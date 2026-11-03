<?php

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\DataGapScanner;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use App\Notifications\Alerts\ProjectDatesScanner;
use App\Notifications\Alerts\StalledScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

// The pause rule (D-66): a project whose own HR center is inactive stops
// alerting on its non-report work; a hosted project is never paused by its
// host; report work keeps firing.
beforeEach(function () {
    $this->city = insertHrCity();
    $this->inactiveCenter = insertHrCenter($this->city, 'Closed Center', 'مركز مغلق', isActive: false);
    $this->activeCenter = insertHrCenter($this->city, 'Open Center', 'مركز مفتوح');
});

/**
 * An active project at the given center with fixed dates.
 *
 * @param  array<string, mixed>  $attributes
 */
function projectAtCenter(int $centerId, array $attributes = []): Project
{
    return Activity::withoutRecording(fn () => Project::factory()->active()->create([
        'center_id' => $centerId,
        'city_id' => test()->city,
        'start_date' => '2026-01-01',
        'end_date' => '2026-09-30',
        ...$attributes,
    ]));
}

/**
 * @return list<int>
 */
function subjectIdsOf(DeadlineCatalog $catalog, string $subjectType): array
{
    return $catalog->items(CarbonImmutable::parse('2026-10-01'))
        ->where('subjectType', $subjectType)
        ->pluck('subjectId')
        ->map(fn (int $id): int => $id)
        ->values()
        ->all();
}

test('a project is paused only when its own center is inactive', function () {
    $paused = projectAtCenter($this->inactiveCenter);
    $running = projectAtCenter($this->activeCenter);
    $hosted = Activity::withoutRecording(fn () => Project::factory()->active()->hostedBy($this->inactiveCenter)->create(['city_id' => $this->city]));

    expect($paused->isPaused())->toBeTrue()
        ->and($running->isPaused())->toBeFalse()
        ->and($hosted->isPaused())->toBeFalse()
        ->and(Project::pausedIds())->toBe([$paused->id]);

    $notPaused = Project::query()->notPaused()->pluck('id')->all();

    expect($notPaused)->not->toContain($paused->id)
        ->toContain($running->id, $hosted->id);
});

test('the deadline catalog skips a paused project\'s tasks, milestones and end date but keeps donor reports', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 06:00'));
    $paused = projectAtCenter($this->inactiveCenter);
    $running = projectAtCenter($this->activeCenter);

    $rows = Activity::withoutRecording(function () use ($paused, $running): array {
        $rows = [];

        foreach (['paused' => $paused, 'running' => $running] as $key => $project) {
            $milestone = Milestone::factory()->create(['project_id' => $project->id, 'planned_end_date' => '2026-09-30', 'status' => MilestoneStatus::Planned]);
            $rows[$key] = [
                'task' => Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'due_date' => '2026-09-30'])->id,
                'milestoneTask' => Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'due_date' => '2026-09-30'])->id,
                'milestone' => $milestone->id,
            ];
        }

        $rows['donorReport'] = DonorReport::factory()->create(['due_on' => '2026-09-28'])->id;

        return $rows;
    });

    $catalog = app(DeadlineCatalog::class);
    $tasks = subjectIdsOf($catalog, 'task');
    $milestones = subjectIdsOf($catalog, 'milestone');
    $projects = subjectIdsOf($catalog, 'project');

    expect($tasks)->not->toContain($rows['paused']['task'], $rows['paused']['milestoneTask'])
        ->toContain($rows['running']['task'], $rows['running']['milestoneTask'])
        ->and($milestones)->not->toContain($rows['paused']['milestone'])
        ->toContain($rows['running']['milestone'])
        ->and($projects)->not->toContain($paused->id)
        ->toContain($running->id)
        ->and(subjectIdsOf($catalog, 'donor_report'))->toContain($rows['donorReport']);
});

test('the stalled scanner skips a paused project\'s task', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $paused = projectAtCenter($this->inactiveCenter);
    $running = projectAtCenter($this->activeCenter);

    $tasks = Activity::withoutRecording(function () use ($assignee, $paused, $running): array {
        $tasks = [];

        foreach ([$paused, $running] as $project) {
            $task = Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'title' => 'Plan']);
            $task->taskAssignees()->delete();
            $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);
            $tasks[] = $task;
        }

        return $tasks;
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00')); // Monday
    foreach ($tasks as $task) {
        $task->update(['status' => TaskStatus::InProgress]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00')); // 8 working days
    $stalled = app(StalledScanner::class)->stalledSubjects(CarbonImmutable::now());

    $taskIds = collect($stalled)->where('type', 'task')->pluck('id')->all();

    expect($taskIds)->toContain($tasks[1]->id)
        ->not->toContain($tasks[0]->id);
});

test('the project dates scanner is quiet about a paused project starting today', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $ownerEmployee = insertHrEmployee();
    $managerEmployee = insertHrEmployee();
    userWithRole('Team Member', $ownerEmployee);
    userWithRole('Managers', $managerEmployee);
    userWithRole('MEAL');
    userWithRole('General Manager');
    $program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $managerEmployee]));
    $attributes = ['program_id' => $program->id, 'user_id' => $ownerEmployee, 'start_date' => '2026-10-14', 'end_date' => '2027-06-30'];

    projectAtCenter($this->inactiveCenter, $attributes);

    expect(app(ProjectDatesScanner::class)->scan(CarbonImmutable::parse('2026-10-14 06:00')))->toBe(0);

    projectAtCenter($this->activeCenter, $attributes);

    expect(app(ProjectDatesScanner::class)->scan(CarbonImmutable::parse('2026-10-14 06:00')))->toBeGreaterThan(0);
});

test('the data gap scanner skips a paused project', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $paused = projectAtCenter($this->inactiveCenter, ['user_id' => $ownerEmployee]);
    $running = projectAtCenter($this->activeCenter, ['user_id' => $ownerEmployee]);

    app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-09-21 06:00'));

    $flagged = UserNotification::query()
        ->forUser($owner)
        ->where('data->key', 'notifications.alerts.data_gap.no_milestones')
        ->where('subject_type', 'project')
        ->pluck('subject_id')
        ->map(fn (int|string $id): int => (int) $id)
        ->all();

    expect($flagged)->toContain($running->id)
        ->not->toContain($paused->id);
});

test('the off-track indicator scanner skips a paused project\'s indicator', function () {
    userWithRole('MEAL');
    $recorder = userWithRole('MEAL');
    $paused = projectAtCenter($this->inactiveCenter);
    $running = projectAtCenter($this->activeCenter);
    $offPace = function (Project $project) use ($recorder): void {
        $indicator = Indicator::factory()->forProject($project->id)->create(['baseline' => '0', 'target' => '100']);
        IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '10', 'date' => '2026-06-30', 'recorded_by' => $recorder->id]);
    };
    $mid = CarbonImmutable::parse('2026-07-01 06:00');

    Activity::withoutRecording(fn () => $offPace($paused));

    expect(app(OffTrackIndicatorScanner::class)->scan($mid))->toBe(0);

    Activity::withoutRecording(fn () => $offPace($running));

    expect(app(OffTrackIndicatorScanner::class)->scan($mid))->toBeGreaterThanOrEqual(1);
});
