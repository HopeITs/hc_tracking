<?php

use Illuminate\Support\Facades\DB;

test('a donor is stored with its name', function () {
    DB::table('donors')->insert([
        'name' => 'Deutsche Gesellschaft für Internationale Zusammenarbeit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assertDatabaseHas('donors', [
        'name' => 'Deutsche Gesellschaft für Internationale Zusammenarbeit',
    ]);
});

test('the same donor name may be stored more than once', function () {
    DB::table('donors')->insert([
        ['name' => 'UNICEF', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'UNICEF', 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(DB::table('donors')->where('name', 'UNICEF')->count())->toBe(2);
});
