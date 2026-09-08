<?php

use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Models\YearlyPlan;

test('a user has the programs and yearly plans they manage', function () {
    $user = User::factory()->create();

    Program::factory(2)->create(['manager_id' => $user->id]);
    Program::factory()->create();
    YearlyPlan::factory()->create(['manager_id' => $user->id]);
    YearlyPlan::factory()->create();

    expect($user->managedPrograms)->toHaveCount(2)
        ->and($user->managedYearlyPlans)->toHaveCount(1);
});

test('a user has the projects and milestones they are responsible for', function () {
    $user = User::factory()->create();

    Project::factory(2)->create(['user_id' => $user->id]);
    Project::factory()->create();
    Milestone::factory()->create(['responsible_user_id' => $user->id]);
    Milestone::factory()->create();

    expect($user->projects)->toHaveCount(2)
        ->and($user->responsibleMilestones)->toHaveCount(1);
});

test('a user separates the tasks assigned to them from the ones they raised', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Task::factory(2)->create(['assigned_to' => $user->id, 'created_by' => $other->id]);
    Task::factory()->create(['assigned_to' => $other->id, 'created_by' => $user->id]);

    expect($user->assignedTasks)->toHaveCount(2)
        ->and($user->createdTasks)->toHaveCount(1)
        ->and($other->assignedTasks)->toHaveCount(1)
        ->and($other->createdTasks)->toHaveCount(2);
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
