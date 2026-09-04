<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

test('a city is not deletable while a project runs in it', function () {
    $user = User::factory()->create();
    $cityId = insertCity();
    insertProject($cityId, insertProgram(insertStrategy(), $user->id), $user->id);

    expect(fn () => DB::table('cities')->where('id', $cityId)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('cities', ['id' => $cityId]);
});

test('a user is not deletable while they are assigned to a project', function () {
    $user = User::factory()->create();
    insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $user->id]);
});
