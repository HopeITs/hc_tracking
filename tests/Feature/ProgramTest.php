<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a program is stored against its strategy and manager', function () {
    $strategyId = insertStrategy();
    $manager = User::factory()->create();

    insertProgram($strategyId, $manager->id);

    $this->assertDatabaseHas('programs', [
        'name' => 'WASH',
        'strategy_id' => $strategyId,
        'manager_id' => $manager->id,
    ]);
});

test('a program cannot reference a strategy that does not exist', function () {
    $manager = User::factory()->create();

    expect(fn () => insertProgram(999, $manager->id))->toThrow(QueryException::class);

    $this->assertDatabaseCount('programs', 0);
});

test('a strategy is not deletable while a program runs under it', function () {
    $strategyId = insertStrategy();
    $manager = User::factory()->create();
    insertProgram($strategyId, $manager->id);

    expect(fn () => DB::table('strategies')->where('id', $strategyId)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('strategies', ['id' => $strategyId]);
});

test('a program stores its manager hr employee id with no foreign key to users', function () {
    $foreignKeyColumns = collect(Schema::getForeignKeys('programs'))->pluck('columns')->flatten();

    expect($foreignKeyColumns)->not->toContain('manager_id');
});
