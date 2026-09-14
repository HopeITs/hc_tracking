<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a project is stored against its city, program and user', function () {
    $user = User::factory()->create();
    $cityId = insertCity();
    $programId = insertProgram(insertStrategy(), $user->id);

    insertProject($cityId, $programId, $user->id);

    $this->assertDatabaseHas('projects', [
        'name' => 'Water Network Rehabilitation',
        'city_id' => $cityId,
        'program_id' => $programId,
        'user_id' => $user->id,
    ]);
});

test('deleting a program deletes the projects beneath it', function () {
    $user = User::factory()->create();
    $programId = insertProgram(insertStrategy(), $user->id);
    insertProject(insertCity(), $programId, $user->id);

    DB::table('programs')->where('id', $programId)->delete();

    $this->assertDatabaseCount('projects', 0);
});

test('projects store an hr city id with no local cities table or foreign key', function () {
    $foreignKeyColumns = collect(Schema::getForeignKeys('projects'))->pluck('columns')->flatten();

    expect(Schema::hasTable('cities'))->toBeFalse()
        ->and($foreignKeyColumns)->not->toContain('city_id');
});

test('a project stores its responsible hr employee id with no foreign key to users', function () {
    $foreignKeyColumns = collect(Schema::getForeignKeys('projects'))->pluck('columns')->flatten();

    expect($foreignKeyColumns)->not->toContain('user_id');
});
