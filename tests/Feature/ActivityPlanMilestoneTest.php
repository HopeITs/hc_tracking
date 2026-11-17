<?php

use App\Actions\AnnualPlans\ManagePlanMilestones;
use App\Enums\IndicatorMeasure;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanMilestone;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Report;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\PlanMilestoneScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanMilestoneDemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('a checkpoint is missed at 4:30 and its completion can be reversed', function () {
    $milestone = ActivityPlanMilestone::factory()->create(['due_date' => '2026-06-15']);

    expect($milestone->status(CarbonImmutable::parse('2026-06-15 16:29')))->toBe('upcoming')
        ->and($milestone->status(CarbonImmutable::parse('2026-06-15 16:30')))->toBe('missed');

    $milestone->update(['completed_at' => '2026-06-15 16:00:00']);
    expect($milestone->fresh()->status(CarbonImmutable::parse('2026-06-16')))->toBe('reached');

    $milestone->update(['completed_at' => null]);
    expect($milestone->fresh()->status(CarbonImmutable::parse('2026-06-16')))->toBe('missed');
});

test('a target milestone changes when dated approved reports are corrected', function (string $measure, float $threshold) {
    $target = PlanIndicator::factory()->for(Indicator::factory()->state(['measure' => IndicatorMeasure::Numeric]))->create(['target' => 100]);
    $milestone = ActivityPlanMilestone::factory()->create([
        'plan_indicator_id' => $target->id, 'measure' => $measure, 'threshold' => $threshold, 'due_date' => '2026-06-15',
    ]);
    $report = Report::factory()->approved()->for($target)->create(['value' => 50, 'planned_date' => '2026-06-15']);
    Report::factory()->approved()->for($target)->create(['value' => 100, 'planned_date' => '2026-06-16']);

    expect($milestone->fresh()->status(CarbonImmutable::parse('2026-06-16')))->toBe('reached');
    $report->update(['value' => 20]);
    expect($milestone->fresh()->status(CarbonImmutable::parse('2026-06-16')))->toBe('missed');
})->with(['number' => ['number', 50.0], 'percentage' => ['percentage', 50.0]]);

test('a report dated in the future does not reach a milestone early', function () {
    $target = PlanIndicator::factory()->for(Indicator::factory()->state(['measure' => IndicatorMeasure::Numeric]))->create(['target' => 100]);
    $milestone = ActivityPlanMilestone::factory()->create([
        'plan_indicator_id' => $target->id, 'measure' => 'number', 'threshold' => 50, 'due_date' => '2026-06-15',
    ]);
    Report::factory()->approved()->for($target)->create(['value' => 50, 'planned_date' => '2026-06-10']);

    expect($milestone->status(CarbonImmutable::parse('2026-06-01')))->toBe('upcoming')
        ->and($milestone->status(CarbonImmutable::parse('2026-06-10')))->toBe('reached');
});

test('only managers of an approved plan can save and complete checkpoints', function () {
    $plan = ActivityPlan::factory()->approved()->create();
    $admin = userWithRole('Super Admin');
    $meal = userWithRole('MEAL');
    $action = app(ManagePlanMilestones::class);
    $input = ['title' => 'Baseline completed', 'due_date' => '2026-06-15', 'measure' => 'checkpoint'];

    expect(fn () => $action->save($meal, $plan, null, $input))->toThrow(AuthorizationException::class);
    expect(fn () => $action->save($admin, ActivityPlan::factory()->create(), null, $input))->toThrow(AuthorizationException::class);
    $milestone = $action->save($admin, $plan, null, $input);
    $action->setCompleted($admin, $plan, $milestone->id, true);
    expect($milestone->fresh()->completed_at)->not->toBeNull();
    $action->setCompleted($admin, $plan, $milestone->id, false);
    expect($milestone->fresh()->completed_at)->toBeNull();
});

test('a milestone rejects a target from another proposal', function () {
    $plan = ActivityPlan::factory()->approved()->create();
    $foreignTarget = PlanIndicator::factory()->forProposal()->create();

    expect(fn () => app(ManagePlanMilestones::class)->save(userWithRole('Super Admin'), $plan, null, [
        'title' => 'Reach 50', 'due_date' => '2026-06-15', 'measure' => 'number',
        'plan_indicator_id' => $foreignTarget->id, 'threshold' => 50,
    ]))->toThrow(ValidationException::class);
});

test('reminders and missed notifications fire once for the appropriate recipients', function () {
    $creator = userWithRole('Managers');
    $generalManager = userWithRole('General Manager');
    $admin = userWithRole('Super Admin');
    $milestone = ActivityPlanMilestone::factory()->create([
        'activity_plan_id' => ActivityPlan::factory()->approved(),
        'created_by' => $creator->id,
        'due_date' => '2026-06-15',
        'title' => 'Reach the checkpoint',
    ]);
    $scanner = app(PlanMilestoneScanner::class);

    $scanner->scan(CarbonImmutable::parse('2026-06-08 09:00'));
    $scanner->scan(CarbonImmutable::parse('2026-06-08 10:00'));
    $scanner->scan(CarbonImmutable::parse('2026-06-12 09:00'));
    $scanner->scan(CarbonImmutable::parse('2026-06-15 16:29'));
    expect(UserNotification::query()->forUser($creator)->where('subject_id', $milestone->id)->count())->toBe(2);

    $scanner->scan(CarbonImmutable::parse('2026-06-15 16:30'));
    $scanner->scan(CarbonImmutable::parse('2026-06-15 16:31'));
    expect(UserNotification::query()->forUser($creator)->where('subject_id', $milestone->id)->count())->toBe(3)
        ->and(UserNotification::query()->forUser($generalManager)->where('subject_id', $milestone->id)->sole()->priority->value)->toBe('info')
        ->and(UserNotification::query()->forUser($admin)->where('subject_id', $milestone->id)->sole()->priority->value)->toBe('critical');
});

test('the desk panel shows milestones only for plans the manager manages', function () {
    $managerEmployee = insertHrEmployee();
    $manager = userWithRole('Managers', $managerEmployee);
    $program = Program::factory()->create(['manager_id' => $managerEmployee]);
    $plan = ActivityPlan::factory()->approved()->create(['program_id' => $program->id]);
    ActivityPlanMilestone::factory()->create(['activity_plan_id' => $plan->id, 'title' => 'Our checkpoint']);
    ActivityPlanMilestone::factory()->create([
        'activity_plan_id' => ActivityPlan::factory()->approved(), 'title' => 'Another program checkpoint',
    ]);

    Livewire::actingAs($manager)->test('desk.panels.plan-milestones', ['size' => 'L'])
        ->assertSee('Our checkpoint')
        ->assertDontSee('Another program checkpoint');

    Livewire::actingAs(userWithRole('MEAL'))->test('desk.panels.plan-milestones', ['size' => 'L'])
        ->assertDontSee('Our checkpoint');
});

test('a manager raises a task from a milestone on the plan proposal, linked back to it', function () {
    $plan = ActivityPlan::factory()->approved()->create();
    $milestone = ActivityPlanMilestone::factory()->create(['activity_plan_id' => $plan->id, 'title' => 'Wells handed over']);
    $assignee = insertHrEmployee();
    $admin = userWithRole('Super Admin');
    $this->actingAs($admin);

    $task = app(ManagePlanMilestones::class)->addTask($admin, $plan, $milestone->id, [
        'title' => '  Book the handover visit  ', 'due_date' => '2026-06-10', 'assignees' => [$assignee],
    ]);

    expect($task->only(['title', 'source_type', 'source_id', 'activity_plan_milestone_id']))
        ->toBe(['title' => 'Book the handover visit', 'source_type' => 'proposal', 'source_id' => $plan->proposal_id, 'activity_plan_milestone_id' => $milestone->id])
        ->and($task->taskAssignees()->pluck('employee_id')->all())->toBe([$assignee]);

    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText(__('annual_plans.milestones.supports', ['name' => 'Wells handed over']));

    $milestone->delete();
    expect($task->fresh()->activity_plan_milestone_id)->toBeNull();
});

test('only a manager of the approved plan can raise a milestone task', function () {
    $plan = ActivityPlan::factory()->approved()->create();
    $milestone = ActivityPlanMilestone::factory()->create(['activity_plan_id' => $plan->id]);
    $foreign = ActivityPlanMilestone::factory()->create(['activity_plan_id' => ActivityPlan::factory()->approved()]);
    $admin = userWithRole('Super Admin');
    $action = app(ManagePlanMilestones::class);

    expect(fn () => $action->addTask(userWithRole('MEAL'), $plan, $milestone->id, ['title' => 'Sneaky']))->toThrow(AuthorizationException::class)
        ->and(fn () => $action->addTask($admin, $plan, $foreign->id, ['title' => 'Elsewhere']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $action->addTask($admin, $plan, $milestone->id, ['title' => '']))->toThrow(ValidationException::class);

    expect(Task::query()->withoutGlobalScopes()->whereNotNull('activity_plan_milestone_id')->count())->toBe(0);
});

test('the demo seeder gives each approved plan one milestone in every state, once', function () {
    $plan = ActivityPlan::factory()->approved()->create();
    ActivityPlan::factory()->create();

    $this->seed(PlanMilestoneDemoSeeder::class);
    $this->seed(PlanMilestoneDemoSeeder::class);

    $statuses = ActivityPlanMilestone::query()->get()->map(fn (ActivityPlanMilestone $milestone): string => $milestone->status())->sort()->values()->all();

    expect(ActivityPlanMilestone::query()->where('activity_plan_id', $plan->id)->count())->toBe(3)
        ->and(ActivityPlanMilestone::query()->count())->toBe(3)
        ->and($statuses)->toBe(['missed', 'reached', 'upcoming']);
});
