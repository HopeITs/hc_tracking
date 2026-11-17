<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\HrCenter;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Alerts\DataGapScanner;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use App\Notifications\Alerts\ProjectDatesScanner;
use App\Notifications\Alerts\StalledScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
| D-66, W-02 slice 2: while the HR center of a project is inactive, its
| non-report work is paused. Reports and donor reports keep going; nothing
| is closed. 2026-11-16 is a Monday.
*/

beforeEach(function () {
    $this->travelTo('2026-11-16 09:00:00');
    $cityId = insertHrCity('Idlib', 'إدلب');
    $this->liveCenter = insertHrCenter($cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->closedCenter = insertHrCenter($cityId, 'Closed Center', 'مركز مغلق', isActive: false);
    $this->program = Program::factory()->create();
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Managers', $this->ownerEmployee);
    $this->makeProject = fn (?int $centerId, array $attributes = []): Project => Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'program_id' => $this->program->id,
        'center_id' => $centerId,
        'user_id' => $this->ownerEmployee,
        'city_id' => $cityId,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-20',
        ...$attributes,
    ]));
    $this->paused = ($this->makeProject)($this->closedCenter, ['name' => 'Paused Wells']);
    $this->live = ($this->makeProject)($this->liveCenter, ['name' => 'Live Wells']);
    $this->hosted = Activity::withoutRecording(fn (): Project => Project::factory()->active()->hostedBy($this->closedCenter)->create([
        'program_id' => $this->program->id,
        'user_id' => $this->ownerEmployee,
        'city_id' => $cityId,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-20',
        'name' => 'Hosted Wells',
    ]));
});

/**
 * @return Collection<int, DeadlineItem>
 */
function pausedCatalogItems(): Collection
{
    return app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-12-31'));
}

test('a project is paused only when its own center is inactive', function () {
    $unmapped = ($this->makeProject)(null);

    expect($this->paused->isPaused())->toBeTrue()
        ->and($this->live->isPaused())->toBeFalse()
        ->and($this->hosted->isPaused())->toBeFalse()
        ->and($unmapped->isPaused())->toBeFalse()
        ->and(Project::query()->paused()->pluck('id')->all())->toBe([$this->paused->id])
        ->and(Project::pausedIds())->toBe([$this->paused->id])
        ->and(Project::query()->notPaused()->pluck('id')->sort()->values()->all())->toBe(collect([$this->live->id, $this->hosted->id, $unmapped->id])->sort()->values()->all());
});

test('a center HR reactivates brings the project back', function () {
    expect($this->paused->isPaused())->toBeTrue();

    DB::connection('hr')->table('centers')->where('id', $this->closedCenter)->update(['is_active' => true]);
    HrCenter::forgetRememberedLookup();

    expect($this->paused->fresh()->isPaused())->toBeFalse();
});

test('the deadline catalog drops the tasks and end dates of a paused project but keeps its reports', function () {
    Activity::withoutRecording(function (): void {
        foreach (['paused' => $this->paused, 'live' => $this->live, 'hosted' => $this->hosted] as $name => $project) {
            Task::factory()->create(['title' => "Task {$name}", 'due_date' => '2026-11-18', 'source_type' => 'project', 'source_id' => $project->id]);
        }

        $planIndicator = PlanIndicator::factory()->create(['project_id' => $this->paused->id]);
        Report::factory()->create(['plan_indicator_id' => $planIndicator->id, 'planned_date' => '2026-11-18', 'user_id' => userWithRole('Team Member')->id]);

        $monthly = MonthlyReport::factory()->create(['project_id' => $this->paused->id, 'period' => '2026-10-01']);
        MonthlyReportLine::factory()->create(['monthly_report_id' => $monthly->id]);

        DonorReport::factory()->create(['title' => 'Interim donor report', 'due_on' => '2026-11-18']);
    });

    $items = pausedCatalogItems();
    $labels = $items->pluck('label')->all();

    expect($labels)->toContain('Task live', 'Task hosted', 'Live Wells', 'Hosted Wells')
        ->not->toContain('Task paused', 'Paused Wells')
        ->and($items->where('subjectType', 'report')->count())->toBe(1)
        ->and($items->where('subjectType', 'monthly_report')->pluck('projectId')->all())->toContain($this->paused->id)
        ->and($labels)->toContain('Interim donor report');
});

test('the project dates scanner skips a paused project', function () {
    $scan = fn (): int => app(ProjectDatesScanner::class)->scan(CarbonImmutable::parse('2026-11-20 06:00'));

    $paused = $this->paused;
    $live = $this->live;
    Project::query()->whereKeyNot($paused->id)->whereKeyNot($live->id)->update(['end_date' => '2030-01-01']);
    Project::query()->whereKey($live->id)->update(['end_date' => '2026-11-20']);

    $withLive = $scan();

    expect($withLive)->toBeGreaterThan(0);

    Project::query()->whereKey($live->id)->update(['end_date' => '2030-01-01']);
    Project::query()->whereKey($paused->id)->update(['end_date' => '2026-11-20']);

    expect($scan())->toBe(0);
});

test('the stalled scanner skips a task of a paused project and keeps one of a live project', function () {
    $stalled = function (Project $project): int {
        $task = Activity::withoutRecording(fn (): Task => Task::factory()->create(['title' => 'Stuck', 'source_type' => 'project', 'source_id' => $project->id]));
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
        $task->update(['status' => 'in progress']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00'));

        return collect(app(StalledScanner::class)->stalledSubjects(CarbonImmutable::today()))->filter(fn (array $stall): bool => $stall['type'] === 'task' && $stall['id'] === $task->id)->count();
    };

    expect($stalled($this->live))->toBe(1)
        ->and($stalled($this->paused))->toBe(0);
});

test('the data gap scanner skips a paused project', function () {
    Project::query()->whereKey([$this->live->id, $this->hosted->id])->update(['status' => ProjectStatus::Closed->value]);

    expect(app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-11-16 06:00')))->toBe(0);

    Project::query()->whereKey($this->live->id)->update(['status' => ProjectStatus::Active->value]);

    expect(app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-11-23 06:00')))->toBeGreaterThan(0);
});

test('the off-track scanner skips the indicators of a paused project', function () {
    $recorder = userWithRole('MEAL');
    $make = fn (Project $project): Indicator => Activity::withoutRecording(function () use ($project, $recorder): Indicator {
        $indicator = Indicator::factory()->create(['project_id' => $project->id, 'baseline' => '0', 'target' => '100', 'name' => 'People reached']);
        IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '10', 'date' => '2026-06-30', 'recorded_by' => $recorder->id]);

        return $indicator;
    });
    $make($this->paused);

    expect(app(OffTrackIndicatorScanner::class)->scan(CarbonImmutable::parse('2026-07-01 06:00')))->toBe(0);

    $make($this->live);

    expect(app(OffTrackIndicatorScanner::class)->scan(CarbonImmutable::parse('2026-07-01 06:00')))->toBeGreaterThan(0);
});

test('a task of a paused project wears the Paused chip in the Tasks panel, and a live one does not', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    Activity::withoutRecording(function () use ($me): void {
        foreach (['Paused task' => $this->paused, 'Live task' => $this->live] as $title => $project) {
            $task = Task::factory()->create(['title' => $title, 'due_date' => '2026-11-18', 'source_type' => 'project', 'source_id' => $project->id]);
            $task->taskAssignees()->delete();
            $task->taskAssignees()->create(['employee_id' => $me->employee_id]);
        }
    });

    $panel = Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->assertSeeText('Paused task')
        ->assertSeeText('Live task')
        ->assertSeeText(__('tasks.paused'));

    expect(substr_count($panel->html(), 'data-test="task-paused-chip"'))->toBe(1);
});

test('the task page says its project is paused, keeping the due date', function () {
    $task = Activity::withoutRecording(fn (): Task => Task::factory()->create(['title' => 'Paused task', 'due_date' => '2026-11-18', 'source_type' => 'project', 'source_id' => $this->paused->id]));

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee('data-test="task-paused-chip"', false)
        ->assertSeeText(__('tasks.paused_center_inactive'));

    expect($task->fresh()->due_date->toDateString())->toBe('2026-11-18');

    $live = Activity::withoutRecording(fn (): Task => Task::factory()->create(['source_type' => 'project', 'source_id' => $this->live->id]));

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('tasks.show', $live))
        ->assertOk()
        ->assertDontSee('data-test="task-paused-chip"', false);
});

test('the chip adds no query per task', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $make = function (int $count) use ($me): void {
        Activity::withoutRecording(function () use ($me, $count): void {
            for ($i = 0; $i < $count; $i++) {
                $task = Task::factory()->create(['due_date' => '2026-11-18', 'source_type' => 'project', 'source_id' => $this->paused->id]);
                $task->taskAssignees()->delete();
                $task->taskAssignees()->create(['employee_id' => $me->employee_id]);
            }
        });
    };
    $countQueries = function () use ($me): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L']);

        return $queries;
    };

    $make(1);
    $withOne = $countQueries();
    $make(4);

    expect($countQueries())->toBeLessThanOrEqual($withOne);
});
