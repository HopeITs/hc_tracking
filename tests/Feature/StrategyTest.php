<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a strategy is stored with the years it spans', function () {
    DB::table('strategies')->insert([
        'name' => 'Country Strategy',
        'start_year' => 2021,
        'end_year' => 2025,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('strategies', [
        'name' => 'Country Strategy',
        'start_year' => 2021,
        'end_year' => 2025,
    ]);
});

test('the same strategy name may be reused for a later period', function () {
    DB::table('strategies')->insert([
        ['name' => 'Country Strategy', 'start_year' => 2016, 'end_year' => 2020, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Country Strategy', 'start_year' => 2021, 'end_year' => 2025, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(DB::table('strategies')->where('name', 'Country Strategy')->count())->toBe(2);
});

test('a negative year is rejected', function () {
    DB::table('strategies')->insert([
        'name' => 'Country Strategy',
        'start_year' => -1,
        'end_year' => 2025,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
