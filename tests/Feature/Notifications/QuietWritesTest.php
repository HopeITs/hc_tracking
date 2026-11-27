<?php

use App\Models\Activity;
use App\Models\Program;
use App\Models\User;
use App\Support\ProgramImport;

test('seeding the database records no activity apart from the demo notifications', function () {
    insertHrSeedReferenceData();

    $this->seed();

    expect(Activity::query()->whereNull('actor_id')->count())->toBe(0);
});

test('bringing hr programs over records no activity', function () {
    insertHrProgram('Hope Center');

    app(ProgramImport::class)->apply();

    expect(Program::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

test('syncing the hr program managers records no activity', function () {
    $program = Activity::withoutRecording(fn (): Program => Program::factory()->unassigned()->create());
    $manager = insertHrEmployee();
    insertHrContract($manager, insertHrPosition(insertHrDepartment(), 'Program Manager', $program->id));

    Program::syncFromHr();

    expect($program->refresh()->manager_id)->toBe($manager)
        ->and(Activity::query()->count())->toBe(0);
});

test('bulk creating and pruning users from hr accounts records no activity', function () {
    $hrUserId = insertHrUser(insertHrEmployee(), 'someone@example.org');

    User::syncFromHrAccounts();
    User::pruneInactiveHrAccounts();

    expect(User::query()->where('hr_user_id', $hrUserId)->exists())->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});
