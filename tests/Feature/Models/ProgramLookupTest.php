<?php

use App\Models\Program;
use Illuminate\Support\Facades\DB;

/**
 * Programs are tracking-owned since W-34b (D-68): the name, code and slug come
 * from our own columns, and no page reaches into HR for them any more.
 */
test('the name accessor reads the local columns and follows the language', function () {
    $program = Program::factory()->create(['name_en' => 'Hope Center', 'name_ar' => 'مركز الأمل']);
    $english = Program::factory()->create(['name_en' => 'Study Zone', 'name_ar' => null]);

    expect($program->name)->toBe('Hope Center')
        ->and($english->name)->toBe('Study Zone');

    app()->setLocale('ar');

    expect($program->fresh()->name)->toBe('مركز الأمل')
        ->and($english->fresh()->name)->toBe('Study Zone');
});

test('the static lookups read the local table', function () {
    $hope = Program::factory()->create(['name_en' => 'Hope Center', 'code' => '001']);
    $study = Program::factory()->create(['name_en' => 'Study Zone', 'code' => '008']);

    expect(Program::nameOf($hope->id))->toBe('Hope Center')
        ->and(Program::nameOf(9_999_999))->toBe('')
        ->and(Program::codeOf($study->id))->toBe('008')
        ->and(Program::namesById())->toBe([$hope->id => 'Hope Center', $study->id => 'Study Zone']);
});

test('a search matches either language and the order follows the name', function () {
    $hope = Program::factory()->create(['name_en' => 'Hope Center', 'name_ar' => 'مركز الأمل']);
    $study = Program::factory()->create(['name_en' => 'Study Zone', 'name_ar' => null]);
    $children = Program::factory()->create(['name_en' => 'Children University', 'name_ar' => 'جامعة الأطفال']);

    expect(Program::idsMatchingName('zone'))->toBe([$study->id])
        ->and(Program::idsMatchingName('الأمل'))->toBe([$hope->id])
        ->and(Program::idsMatchingName(''))->toHaveCount(3)
        ->and(Program::idsOrderedByName())->toBe([$children->id, $hope->id, $study->id])
        ->and(Program::options()->pluck('id')->all())->toBe([$children->id, $hope->id, $study->id])
        ->and(Program::query()->whereNameLike('hope')->pluck('id')->all())->toBe([$hope->id]);
});

test('listing programs by name costs no HR query', function () {
    Program::factory()->count(3)->create();

    DB::connection('hr')->flushQueryLog();
    DB::connection('hr')->enableQueryLog();

    Program::options()->each(fn (Program $program): string => $program->name);
    Program::namesById();
    Program::codeOf(1);
    Program::idsOrderedByName();

    expect(DB::connection('hr')->getQueryLog())->toBe([]);
});

test('a program is addressed by its own slug', function () {
    $program = Program::factory()->create(['name_en' => 'Hope Center']);

    expect($program->slug)->toBe('hope-center')
        ->and($program->getRouteKey())->toBe('hope-center')
        ->and(Program::query()->where('slug', 'hope-center')->value('id'))->toBe($program->id);
});
