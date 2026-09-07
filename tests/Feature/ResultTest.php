<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a new result checks out quarterly and stands on its own', function () {
    $result = DB::table('results')->find(insertResult());

    expect($result->checkout_period)->toBe('quarter')
        ->and($result->parent_id)->toBeNull()
        ->and($result->program_id)->toBeNull();
});

test('a result nests under a parent result', function () {
    $goalId = insertResult(type: 'goal', statement: 'Reduced waterborne disease.');

    $outcomeId = insertResult($goalId);

    $this->assertDatabaseHas('results', ['id' => $outcomeId, 'parent_id' => $goalId]);
});

test('deleting a result deletes the results beneath it', function () {
    $goalId = insertResult(type: 'goal', statement: 'Reduced waterborne disease.');
    $outcomeId = insertResult($goalId);
    insertResult($outcomeId, type: 'output', statement: 'Boreholes rehabilitated.');

    DB::table('results')->where('id', $goalId)->delete();

    $this->assertDatabaseCount('results', 0);
});

test('deleting a program detaches its results instead of deleting them', function () {
    $user = User::factory()->create();
    $programId = insertProgram(insertStrategy(), $user->id);
    $resultId = insertResult(programId: $programId);

    DB::table('programs')->where('id', $programId)->delete();

    $this->assertDatabaseHas('results', ['id' => $resultId, 'program_id' => null]);
});

test('a result cannot be stored without a statement', function () {
    DB::table('results')->insert([
        'type' => 'outcome',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
