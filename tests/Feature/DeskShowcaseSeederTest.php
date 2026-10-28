<?php

use App\Models\Activity;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\DeskShowcaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('the showcase seeder fills the empty desk cards once and records no activity', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->role('Super Admin')->create(['employee_id' => insertHrEmployee()]);
    foreach (range(1, 3) as $index) {
        User::factory()->create(['employee_id' => insertHrEmployee()]);
    }
    Activity::withoutRecording(function (): void {
        Project::factory(10)->create();
        Donor::factory(2)->create();
    });
    $activityBefore = Activity::query()->count();

    $this->seed(DeskShowcaseSeeder::class);
    $counts = fn (): array => [
        Task::query()->withoutGlobalScopes()->where('title', 'like', 'Showcase:%')->count(),
        Task::query()->withoutGlobalScopes()->whereNotNull('delivery_state')->count(),
        TransfersTracking::query()->count(),
    ];
    $first = $counts();
    $this->seed(DeskShowcaseSeeder::class);

    expect($first[0])->toBe(9)
        ->and($first[1])->toBe(3)
        ->and($counts())->toBe($first)
        ->and(Activity::query()->count())->toBe($activityBefore);
});
