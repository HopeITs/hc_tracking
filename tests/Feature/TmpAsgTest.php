<?php

use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Program;
use App\Models\Project;
use App\Models\UserNotification;

test('tmp: assignment lands on the row', function () {
    $managerEmployee = insertHrEmployee(firstNameEn: 'Mona', lastNameEn: 'Manager');
    userWithRole('MEAL', $managerEmployee);
    $ownerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $owner = userWithRole('Managers', $ownerEmployee);
    $assigner = userWithRole('General Manager', insertHrEmployee());

    $project = Activity::withoutRecording(fn () => Project::factory()->create([
        'name' => 'Winter Support',
        'program_id' => Program::factory()->create(['manager_id' => $managerEmployee])->id,
        'user_id' => insertHrEmployee(),
    ]));
    $row = Activity::withoutRecording(fn () => rowFor($project));

    test()->actingAs($assigner);
    $project->update(['user_id' => $ownerEmployee]);

    dump(Assignment::query()->get(['id', 'assignable_type', 'assignable_id', 'field', 'employee_id', 'status'])->toArray());
    dump(['rowId' => $row->id, 'projectId' => $project->id]);
    $n = UserNotification::query()->forUser($owner)->first();
    dump(['subject' => $n?->subject_type.'#'.$n?->subject_id, 'actions' => $n?->data['actions'] ?? null]);
    expect(true)->toBeTrue();
});
