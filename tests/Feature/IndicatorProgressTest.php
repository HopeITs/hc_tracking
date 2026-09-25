<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a reading keeps its exact figure and its source of verification', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicatorId = insertIndicator(insertResult());

    $readingId = DB::table('indicator_progress')->insertGetId([
        'indicator_id' => $indicatorId,
        'date' => '2026-03-31',
        'value' => '9999999999.99',
        'source_of_verification' => 'Household survey',
        'recorded_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reading = DB::table('indicator_progress')->find($readingId);

    expect($reading->value)->toBe('9999999999.99')
        ->and($reading->source_of_verification)->toBe('Household survey')
        ->and($reading->date)->toBe('2026-03-31');
});

test('deleting an indicator deletes the readings taken against it', function () {
    $user = User::factory()->role('General Manager')->create();
    $indicatorId = insertIndicator(insertResult());
    insertIndicatorProgress($indicatorId, $user->id);

    DB::table('indicators')->where('id', $indicatorId)->delete();

    $this->assertDatabaseCount('indicator_progress', 0);
});

test('a recorder is not deletable while their readings remain', function () {
    $user = User::factory()->role('General Manager')->create();
    insertIndicatorProgress(insertIndicator(insertResult()), $user->id);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('an indicator carries an optional baseline target unit and frequency', function () {
    $indicatorId = insertIndicator(insertResult());

    DB::table('indicators')->where('id', $indicatorId)->update([
        'unit' => 'households',
        'baseline' => '1200.00',
        'target' => '9000.00',
    ]);

    $indicator = DB::table('indicators')->find($indicatorId);

    expect($indicator->unit)->toBe('households')
        ->and($indicator->baseline)->toBe('1200.00')
        ->and($indicator->target)->toBe('9000.00')
        ->and($indicator->frequency)->toBe('quarterly');
});

test('an indicator scoped to a project loses that scope when the project goes', function () {
    $user = User::factory()->role('General Manager')->create();
    $projectId = insertProject(insertCity(), insertProgram(insertStrategy(), $user->id), $user->id);
    $indicatorId = insertIndicator(insertResult());

    DB::table('indicators')->where('id', $indicatorId)->update(['project_id' => $projectId]);
    DB::table('projects')->where('id', $projectId)->delete();

    // nullOnDelete, not cascade: an indicator outlives the project it was
    // scoped to, because it still measures its result.
    expect(DB::table('indicators')->find($indicatorId)->project_id)->toBeNull();
});
