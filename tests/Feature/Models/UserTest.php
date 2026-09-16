<?php

use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;

test('user timestamps are immutable and preserve their time', function (string $attribute) {
    $user = User::factory()->withTwoFactor()->create([
        $attribute => '2026-09-08 13:24:35',
    ])->fresh();

    $date = $user->getAttribute($attribute);

    expect($date)->toBeInstanceOf(CarbonImmutable::class);
    $nextDay = $date->addDay();
    expect($date->toDateTimeString())->toBe('2026-09-08 13:24:35')
        ->and($nextDay->toDateTimeString())->toBe('2026-09-09 13:24:35');
    expect($user->isDirty($attribute))->toBeFalse();
})->with(['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at']);

test('unverified user confirmation dates remain null', function () {
    $user = User::factory()->unverified()->create()->fresh();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

test('an hr employee has the programs and annual plans they manage', function () {
    $employee = HrEmployee::find(insertHrEmployee());

    Program::factory(2)->create(['manager_id' => $employee->id]);
    Program::factory()->create();
    AnnualPlan::factory()->create(['manager_id' => $employee->id]);
    AnnualPlan::factory()->create();

    expect($employee->managedPrograms)->toHaveCount(2)
        ->and($employee->managedAnnualPlans)->toHaveCount(1);
});

test('an hr employee has the projects and milestones they are responsible for', function () {
    $employee = HrEmployee::find(insertHrEmployee());

    Project::factory(2)->create(['user_id' => $employee->id]);
    Project::factory()->create();
    Milestone::factory()->create(['responsible_user_id' => $employee->id]);
    Milestone::factory()->create();

    expect($employee->projects)->toHaveCount(2)
        ->and($employee->responsibleMilestones)->toHaveCount(1);
});

test('tasks are assigned to hr employees but raised by users', function () {
    $employee = HrEmployee::find(insertHrEmployee());
    $user = User::factory()->create();

    Task::factory(2)->create(['assigned_to' => $employee->id, 'created_by' => $user->id]);
    Task::factory()->create();

    expect($employee->assignedTasks)->toHaveCount(2)
        ->and($user->createdTasks)->toHaveCount(2);
});

test('a user has the task updates and reports they submitted', function () {
    $user = User::factory()->create();

    TaskUpdate::factory(2)->create(['user_id' => $user->id]);
    TaskUpdate::factory()->create();
    Report::factory()->create(['user_id' => $user->id]);
    Report::factory()->create();

    expect($user->taskUpdates)->toHaveCount(2)
        ->and($user->reports)->toHaveCount(1);
});
