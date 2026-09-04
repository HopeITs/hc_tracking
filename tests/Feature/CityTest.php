<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a city is stored with its name', function () {
    DB::table('cities')->insert([
        'name' => 'Deir ez-Zor',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('cities', ['name' => 'Deir ez-Zor']);
});

test('the same city name cannot be stored twice', function () {
    DB::table('cities')->insert([
        'name' => 'Aleppo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cities')->insert([
        'name' => 'Aleppo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
