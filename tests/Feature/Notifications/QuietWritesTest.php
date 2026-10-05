<?php

use App\Models\Activity;
use App\Models\Program;
use App\Models\User;

test('seeding the database records no activity apart from the demo notifications', function () {
    insertHrSeedReferenceData();

    $this->seed();

    expect(Activity::query()->whereNull('actor_id')->count())->toBe(0);
});

test('mirroring hr programs records no activity', function () {
    insertHrProgram('Hope Center');

    Program::syncFromHr();

    expect(Program::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

test('bulk creating and pruning users from hr accounts records no activity', function () {
    $hrUserId = insertHrUser(insertHrEmployee(), 'someone@example.org');

    User::syncFromHrAccounts();
    User::pruneInactiveHrAccounts();

    expect(User::query()->where('hr_user_id', $hrUserId)->exists())->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});
