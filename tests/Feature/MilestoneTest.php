<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a new milestone is planned, first in sequence and carries no weight', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);

    $milestone = DB::table('milestones')->find(insertMilestone($projectId, $user->id));

    expect($milestone->status)->toBe('planned')
        ->and((int) $milestone->sequence)->toBe(1)
        ->and($milestone->weight_percentage)->toBe('0.00')
        ->and($milestone->planned_end_date)->toBeNull()
        ->and($milestone->actual_start_date)->toBeNull()
        ->and($milestone->actual_end_date)->toBeNull()
        ->and($milestone->description)->toBeNull()
        ->and($milestone->parent_milestone_id)->toBeNull();
});

test('a milestone can be nested under a parent milestone', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $parentId = insertMilestone($projectId, $user->id, name: 'Design phase');

    $childId = insertMilestone($projectId, $user->id, $parentId, 'Technical drawings');

    $this->assertDatabaseHas('milestones', [
        'id' => $childId,
        'parent_milestone_id' => $parentId,
    ]);
});

test('deleting a parent milestone deletes the milestones nested under it', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $parentId = insertMilestone($projectId, $user->id, name: 'Design phase');
    insertMilestone($projectId, $user->id, $parentId, 'Technical drawings');

    DB::table('milestones')->where('id', $parentId)->delete();

    $this->assertDatabaseCount('milestones', 0);
});

test('deleting a project deletes its milestones', function () {
    $user = User::factory()->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    insertMilestone($projectId, $user->id);

    DB::table('projects')->where('id', $projectId)->delete();

    $this->assertDatabaseCount('milestones', 0);
});

test('a milestone stores its responsible hr employee id with no foreign key to users', function () {
    $foreignKeyColumns = collect(Schema::getForeignKeys('milestones'))->pluck('columns')->flatten();

    expect($foreignKeyColumns)->not->toContain('responsible_user_id');
});
