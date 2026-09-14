<?php

use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insert a yearly plan for the given program, manager and year.
 */
function insertYearlyPlan(int $programId, int $managerId, int $year = 2026): void
{
    YearlyPlan::factory()->create([
        'program_id' => $programId,
        'manager_id' => $managerId,
        'year' => $year,
    ]);
}

test('a yearly plan is stored against its program and manager', function () {
    $manager = User::factory()->create();
    $programId = insertProgram(insertStrategy(), $manager->id);

    insertYearlyPlan($programId, $manager->id, 2026);

    $this->assertDatabaseHas('yearly_plans', [
        'program_id' => $programId,
        'manager_id' => $manager->id,
        'year' => 2026,
    ]);
});

test('deleting a program deletes the yearly plans beneath it', function () {
    $manager = User::factory()->create();
    $programId = insertProgram(insertStrategy(), $manager->id);
    insertYearlyPlan($programId, $manager->id);

    DB::table('programs')->where('id', $programId)->delete();

    $this->assertDatabaseCount('yearly_plans', 0);
});

test('a yearly plan stores its manager hr employee id with no foreign key to users', function () {
    $foreignKeyColumns = collect(Schema::getForeignKeys('yearly_plans'))->pluck('columns')->flatten();

    expect($foreignKeyColumns)->not->toContain('manager_id');
});

test('a program cannot hold two plans for the same year', function () {
    $manager = User::factory()->create();
    $programId = insertProgram(insertStrategy(), $manager->id);
    insertYearlyPlan($programId, $manager->id, 2026);

    expect(fn () => insertYearlyPlan($programId, $manager->id, 2026))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('yearly_plans', 1);
});

test('two programs may each hold a plan for the same year', function () {
    $manager = User::factory()->create();
    $strategyId = insertStrategy();
    $wash = insertProgram($strategyId, $manager->id, 'WASH');
    $health = insertProgram($strategyId, $manager->id, 'Health');

    insertYearlyPlan($wash, $manager->id, 2026);
    insertYearlyPlan($health, $manager->id, 2026);

    $this->assertDatabaseCount('yearly_plans', 2);
});
