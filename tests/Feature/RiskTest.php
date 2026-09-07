<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Insert a project owned by a freshly created user, and return its id.
 */
function insertProjectForNewUser(): int
{
    $user = User::factory()->create();

    return insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
}

test('a newly registered risk has not happened yet', function () {
    $id = DB::table('risks')->insertGetId([
        'name' => 'Fuel supply disruption',
        'project_id' => insertProjectForNewUser(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $risk = DB::table('risks')->find($id);

    expect((bool) $risk->happened)->toBeFalse()
        ->and($risk->impact)->toBeNull()
        ->and($risk->solution)->toBeNull();
});

test('a realized risk records its impact and solution', function () {
    $id = DB::table('risks')->insertGetId([
        'name' => 'Fuel supply disruption',
        'project_id' => insertProjectForNewUser(),
        'impact' => 'Water trucking contracts lapse for six weeks, affecting 4,200 households.',
        'happened' => true,
        'solution' => 'Switched to a secondary supplier and re-tendered the trucking contract.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $risk = DB::table('risks')->find($id);

    expect((bool) $risk->happened)->toBeTrue()
        ->and($risk->impact)->toBe('Water trucking contracts lapse for six weeks, affecting 4,200 households.')
        ->and($risk->solution)->toBe('Switched to a secondary supplier and re-tendered the trucking contract.');
});

test('deleting a project deletes its risk register', function () {
    $projectId = insertProjectForNewUser();
    DB::table('risks')->insert([
        'name' => 'Fuel supply disruption',
        'project_id' => $projectId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('projects')->where('id', $projectId)->delete();

    $this->assertDatabaseCount('risks', 0);
});
