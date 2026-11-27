<?php

use Illuminate\Support\Facades\DB;

/**
 * Error FOYX8YKJ: a program with no slug broke the Results framework page,
 * because `route('programs.show', $program)` had no key to put in the URL.
 */
test('the backfill gives every program without a slug a unique one and keeps the rest', function () {
    $now = now();
    DB::table('programs')->insert([
        ['id' => 9001, 'name_en' => 'Wells', 'code' => '042', 'slug' => 'wells', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 9002, 'name_en' => 'Wells', 'code' => '043', 'slug' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 9003, 'name_en' => '', 'code' => 'X-7', 'slug' => '', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 9004, 'name_en' => '', 'code' => '', 'slug' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    (require glob(database_path('migrations/*_backfill_missing_program_slugs.php'))[0])->up();

    expect(DB::table('programs')->whereIn('id', [9001, 9002, 9003, 9004])->orderBy('id')->pluck('slug')->all())
        ->toBe(['wells', 'wells-2', 'x-7', 'program-9004']);
});
