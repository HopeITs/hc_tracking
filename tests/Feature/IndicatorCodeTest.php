<?php

use App\Actions\IndicatorLibrary\SaveLibraryIndicator;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Support\IndicatorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IndicatorCode::next() inside the transaction it requires.
 *
 * @return array{code: string, serial: int}
 */
function nextIndicatorCode(?int $programId, string $levelRef): array
{
    return DB::transaction(fn (): array => IndicatorCode::next($programId, $levelRef));
}

it('builds the code from the HR program code, the level and the next serial', function (string $levelRef, string $expected) {
    $programId = insertHrProgram(code: '002');
    LibraryIndicator::factory()->create(['program_id' => $programId, 'level_ref' => 'OP1.2', 'serial' => 1, 'code' => '002.OP1.2.1']);
    LibraryIndicator::factory()->create(['program_id' => $programId, 'level_ref' => 'OP1.2', 'serial' => 2, 'code' => '002.OP1.2.2']);

    expect(nextIndicatorCode($programId, $levelRef)['code'])->toBe($expected);
})->with([
    'third entry at an output level' => ['OP1.2', '002.OP1.2.3'],
    'first entry at the goal' => ['G', '002.G.1'],
]);

it('uses HC for an institutional entry', function () {
    expect(nextIndicatorCode(null, 'OP1.1'))->toBe(['code' => 'HC.OP1.1.1', 'serial' => 1]);
});

it('numbers each program and level on its own', function () {
    $first = insertHrProgram(code: '002');
    $second = insertHrProgram(code: '005');
    LibraryIndicator::factory()->create(['program_id' => $first, 'level_ref' => 'OC1', 'serial' => 1, 'code' => '002.OC1.1']);

    expect(nextIndicatorCode($first, 'OC1')['serial'])->toBe(2)
        ->and(nextIndicatorCode($first, 'OC2')['serial'])->toBe(1)
        ->and(nextIndicatorCode($second, 'OC1')['serial'])->toBe(1)
        ->and(nextIndicatorCode(null, 'OC1')['serial'])->toBe(1);
});

it('never gives out the serial of a deactivated entry again', function () {
    $programId = insertHrProgram(code: '002');
    LibraryIndicator::factory()->create(['program_id' => $programId, 'level_ref' => 'OP1', 'serial' => 1, 'code' => '002.OP1.1']);
    LibraryIndicator::factory()->inactive()->create(['program_id' => $programId, 'level_ref' => 'OP1', 'serial' => 2, 'code' => '002.OP1.2']);

    expect(nextIndicatorCode($programId, 'OP1')['code'])->toBe('002.OP1.3');
});

it('gives two saves at the same program and level different codes', function () {
    $this->actingAs(userWithRole('MEAL'));
    $programId = insertHrProgram(code: '002');
    $input = libraryIndicatorInput(['institutional' => false, 'program_id' => $programId, 'level_ref' => 'OP1.2']);

    $first = app(SaveLibraryIndicator::class)->handle(null, $input);
    $second = app(SaveLibraryIndicator::class)->handle(null, $input);

    expect([$first->code, $second->code])->toBe(['002.OP1.2.1', '002.OP1.2.2']);
});

it('refuses to hand out a code outside a transaction', function () {
    // RefreshDatabase runs every test inside a transaction; leave it for this call.
    DB::rollBack();

    try {
        expect(fn () => IndicatorCode::next(null, 'G'))->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});

it('refuses a program whose code HR has given to two programs', function () {
    insertHrProgram(code: '003');
    $programId = insertHrProgram(code: '003');

    nextIndicatorCode($programId, 'OP1');
})->throws(ValidationException::class, "This program's code is duplicated in HR. Ask the HR team to fix it.");

it('writes nothing when the HR program code is duplicated', function () {
    $this->actingAs(userWithRole('MEAL'));
    insertHrProgram(code: '009');
    $programId = insertHrProgram(code: '009');

    try {
        app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput(['institutional' => false, 'program_id' => $programId]));
    } catch (ValidationException) {
    }

    expect(LibraryIndicator::query()->count())->toBe(0);
});

it('keeps the code when an entry in use is renamed', function () {
    $this->actingAs(userWithRole('MEAL'));
    $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput());
    Indicator::factory()->create(['library_indicator_id' => $entry->id]);

    $saved = app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['name_ar' => 'عدد الأسر']));

    expect($saved->code)->toBe('HC.OP1.1')
        ->and($saved->name_ar)->toBe('عدد الأسر');
});

it('refuses to move an entry in use to another level, keeping its code', function () {
    $this->actingAs(userWithRole('MEAL'));
    $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput());
    Indicator::factory()->create(['library_indicator_id' => $entry->id]);

    expect(fn () => app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['level_ref' => 'OP2'])))
        ->toThrow(ValidationException::class, 'The program and level cannot change because this indicator is in use.');

    expect($entry->fresh()->only(['code', 'level_ref']))->toBe(['code' => 'HC.OP1.1', 'level_ref' => 'OP1']);
});

it('gives an unused entry moved to another program and level a new code', function () {
    $this->actingAs(userWithRole('MEAL'));
    $programId = insertHrProgram(code: '002');
    $entry = app(SaveLibraryIndicator::class)->handle(null, libraryIndicatorInput());

    $moved = app(SaveLibraryIndicator::class)->handle($entry->id, libraryIndicatorInput(['institutional' => false, 'program_id' => $programId, 'level_ref' => 'AC1.1']));

    expect($moved->only(['code', 'serial', 'program_id']))->toBe(['code' => '002.AC1.1.1', 'serial' => 1, 'program_id' => $programId]);
});
