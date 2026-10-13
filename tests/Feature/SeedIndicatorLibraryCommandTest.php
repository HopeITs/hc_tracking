<?php

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Program;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * A tracking program whose HR row carries the given code, and its id.
 */
function programWithHrCode(string $code): int
{
    $programId = Program::factory()->create()->id;
    DB::connection('hr')->table('programs')->where('id', $programId)->update(['code' => $code]);

    return $programId;
}

/**
 * An outcome with one output under it in the program, and the output's id.
 */
function outputUnderOutcome(?int $programId): int
{
    return insertResult(insertResult(null, $programId), $programId, ResultType::Output);
}

/**
 * The library code an indicator was linked to.
 */
function linkedCode(int $indicatorId): ?string
{
    return LibraryIndicator::query()->whereKey(DB::table('indicators')->where('id', $indicatorId)->value('library_indicator_id'))->value('code');
}

test('every unlinked indicator is linked to a legacy entry and given its code', function () {
    $outputId = outputUnderOutcome(programWithHrCode('002'));
    $indicatorId = insertIndicator($outputId, 'Number of people reached');

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    $entry = LibraryIndicator::query()->sole();
    expect($entry->code)->toBe('002.OP1.1.1')
        ->and($entry->level_ref)->toBe('OP1.1')
        ->and($entry->is_legacy)->toBeTrue()
        ->and($entry->is_active)->toBeTrue()
        ->and($entry->measure)->toBe(IndicatorMeasure::Numeric)
        ->and($entry->frequency)->toBe(CollectionFrequency::Quarterly)
        ->and($entry->suggested_level)->toBe(ResultType::Output);
    $this->assertDatabaseHas('indicators', ['id' => $indicatorId, 'library_indicator_id' => $entry->id, 'code' => '002.OP1.1.1']);
});

test('an indicator already in the library is left alone', function () {
    $outputId = outputUnderOutcome(programWithHrCode('002'));
    $entry = LibraryIndicator::factory()->create();
    $indicatorId = Indicator::factory()->create(['result_id' => $outputId, 'library_indicator_id' => $entry->id, 'code' => $entry->code])->id;

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(LibraryIndicator::query()->count())->toBe(1)
        ->and(linkedCode($indicatorId))->toBe($entry->code);
});

test('the level comes from the node type and its place in the program framework', function () {
    $programId = programWithHrCode('002');
    $impactId = insertResult(null, $programId, ResultType::Impact);
    $firstOutcomeId = insertResult(null, $programId);
    $secondOutcomeId = insertResult(null, $programId);
    $firstOutputId = insertResult($firstOutcomeId, $programId, ResultType::Output);
    $secondOutputId = insertResult($firstOutcomeId, $programId, ResultType::Output);
    $nestedOutputId = insertResult($firstOutputId, $programId, ResultType::Output);
    $looseOutputId = insertResult($impactId, $programId, ResultType::Output);
    $ids = [
        'impact' => insertIndicator($impactId, 'Goal indicator'),
        'second outcome' => insertIndicator($secondOutcomeId, 'Second outcome indicator'),
        'second output' => insertIndicator($secondOutputId, 'Second output indicator'),
        'nested output' => insertIndicator($nestedOutputId, 'Nested output indicator'),
        'output with no outcome' => insertIndicator($looseOutputId, 'Loose output indicator'),
    ];

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(array_map(linkedCode(...), $ids))->toBe([
        'impact' => '002.G.1',
        'second outcome' => '002.OC2.1',
        'second output' => '002.OP1.2.1',
        'nested output' => '002.OP1.1.1.1',
        'output with no outcome' => '002.OP1.1',
    ]);
});

test('names that differ only in case, spacing or tashkeel share one entry', function () {
    $programId = programWithHrCode('002');
    $outcomeId = insertResult(null, $programId);
    // indicators_result_id_name_unique already folds these variants, so each sits on its own output.
    $ids = array_map(
        fn (string $name): int => insertIndicator(insertResult($outcomeId, $programId, ResultType::Output), $name),
        ['Number of people reached', '  number of   PEOPLE reached ', 'عدد الأشخاص', 'عَدَد الأشخاص'],
    );

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(LibraryIndicator::query()->count())->toBe(2)
        ->and(array_map(linkedCode(...), $ids))->toBe(['002.OP1.1.1', '002.OP1.1.1', '002.OP1.3.1', '002.OP1.3.1']);
});

test('one name used with two measures becomes two entries and a conflict line', function () {
    $outputId = outputUnderOutcome(programWithHrCode('002'));
    insertIndicator($outputId, 'Families reached');
    Indicator::factory()->create(['result_id' => outputUnderOutcome(programWithHrCode('005')), 'name' => 'Families reached', 'measure' => IndicatorMeasure::Percentage]);

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain('Conflict: "Families reached" is used with more than one measure (numeric, percentage)')
        ->assertSuccessful();

    expect(LibraryIndicator::query()->orderBy('measure')->pluck('measure')->all())->toBe([IndicatorMeasure::Numeric, IndicatorMeasure::Percentage]);
});

test('an entry used in several programs, or in none, is institutional', function () {
    insertIndicator(outputUnderOutcome(programWithHrCode('002')), 'Number of people reached');
    insertIndicator(outputUnderOutcome(programWithHrCode('005')), 'Number of people reached');
    $withoutProgramId = insertIndicator(outputUnderOutcome(null), 'Number of sessions held');

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(LibraryIndicator::query()->orderBy('code')->pluck('program_id', 'code')->all())->toBe(['HC.OP1.1.1' => null, 'HC.OP1.1.2' => null])
        ->and(linkedCode($withoutProgramId))->toBe('HC.OP1.1.2');
});

test('a name is stored in the column of its script and English-only names are listed for review', function () {
    $outputId = outputUnderOutcome(programWithHrCode('002'));
    insertIndicator($outputId, 'عدد الأسر المستفيدة');
    insertIndicator($outputId, 'Number of people reached');

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain('For MEAL review')
        ->expectsOutputToContain('002.OP1.1.2  Number of people reached')
        ->assertSuccessful();

    $this->assertDatabaseHas('library_indicators', ['code' => '002.OP1.1.1', 'name_ar' => 'عدد الأسر المستفيدة', 'name_en' => null]);
    $this->assertDatabaseHas('library_indicators', ['code' => '002.OP1.1.2', 'name_ar' => 'Number of people reached', 'name_en' => 'Number of people reached']);
});

test('a percentage entry has no unit and a fixed denominator', function () {
    Indicator::factory()->create(['result_id' => outputUnderOutcome(programWithHrCode('002')), 'name' => 'Share of families reached', 'measure' => IndicatorMeasure::Percentage, 'unit' => '%']);

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    $entry = LibraryIndicator::query()->sole();
    expect($entry->unit)->toBeNull()
        ->and($entry->denominator_basis)->toBe(DenominatorBasis::Fixed);
});

test('a second run creates nothing', function () {
    insertIndicator(outputUnderOutcome(programWithHrCode('002')), 'Number of people reached');
    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain('Every indicator is already in the library.')
        ->assertSuccessful();

    expect(LibraryIndicator::query()->count())->toBe(1);
});

test('a later indicator joins the legacy entry with its name, never an entry MEAL made', function () {
    $programId = programWithHrCode('002');
    $outcomeId = insertResult(null, $programId);
    insertIndicator(insertResult($outcomeId, $programId, ResultType::Output), 'Number of people reached');
    $this->artisan('meal:seed-indicator-library')->assertSuccessful();
    LibraryIndicator::factory()->create(['name_ar' => 'Number of sessions held', 'name_en' => 'Number of sessions held']);
    $laterOutputId = insertResult($outcomeId, $programId, ResultType::Output);
    $laterId = insertIndicator($laterOutputId, 'Number of people reached');
    $sessionsId = insertIndicator($laterOutputId, 'Number of sessions held');

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(linkedCode($laterId))->toBe('002.OP1.1.1')
        ->and(linkedCode($sessionsId))->toBe('002.OP1.2.1')
        ->and(LibraryIndicator::query()->count())->toBe(3);
});

test('a dry run prints the proposed codes and changes nothing', function () {
    $indicatorId = insertIndicator(outputUnderOutcome(null), 'Number of people reached');
    LibraryIndicator::factory()->create(['level_ref' => 'OP1.1', 'serial' => 1, 'code' => 'HC.OP1.1.1']);
    $activities = Activity::query()->count();

    $this->artisan('meal:seed-indicator-library', ['--dry-run' => true])
        ->expectsOutputToContain('HC.OP1.1.2')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertSuccessful();

    expect(LibraryIndicator::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe($activities);
    $this->assertDatabaseHas('indicators', ['id' => $indicatorId, 'library_indicator_id' => null, 'code' => null]);
});

test('a program code HR gives to two programs stops the run, naming it', function (bool $dryRun) {
    $programId = programWithHrCode('003');
    programWithHrCode('003');
    $indicatorId = insertIndicator(outputUnderOutcome($programId), 'Number of people reached');
    insertIndicator(outputUnderOutcome(null), 'Number of sessions held');

    $this->artisan('meal:seed-indicator-library', ['--dry-run' => $dryRun])
        ->expectsOutputToContain('HC.OP1.1.1')
        ->expectsOutputToContain('HR program code 003 is used by more than one program')
        ->assertFailed();

    expect(LibraryIndicator::query()->count())->toBe(0);
    $this->assertDatabaseHas('indicators', ['id' => $indicatorId, 'library_indicator_id' => null]);
})->with(['run' => false, 'dry run' => true]);

test('a program with no code in HR stops the run, naming it', function () {
    $programId = programWithHrCode('');
    $indicatorId = insertIndicator(outputUnderOutcome($programId), 'Number of people reached');

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain("Program {$programId} has no code in HR.")
        ->assertFailed();

    expect(LibraryIndicator::query()->count())->toBe(0);
    $this->assertDatabaseHas('indicators', ['id' => $indicatorId, 'library_indicator_id' => null]);
});

test('a program code HR duplicates after the check is refused and nothing is written', function () {
    $programId = programWithHrCode('002');
    $indicatorId = insertIndicator(outputUnderOutcome($programId), 'Number of people reached');
    $duplicated = false;
    // The first HR read is the code check; HR gives the code to a second program right after it.
    DB::listen(function (QueryExecuted $query) use (&$duplicated): void {
        if (! $duplicated && $query->connectionName === 'hr') {
            $duplicated = true;
            insertHrProgram(code: '002');
        }
    });

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain(__('indicator_library.program_code_duplicated'))
        ->assertFailed();

    expect(LibraryIndicator::query()->count())->toBe(0);
    $this->assertDatabaseHas('indicators', ['id' => $indicatorId, 'library_indicator_id' => null]);
});

test('a parent in another program counts as no parent', function () {
    $otherOutcomeId = insertResult(null, programWithHrCode('002'));
    $programId = programWithHrCode('005');
    insertResult(null, $programId);
    $indicatorId = insertIndicator(insertResult($otherOutcomeId, $programId, ResultType::Output), 'Number of people reached');

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(linkedCode($indicatorId))->toBe('005.OP1.1');
});

test('more indicators than one write chunk holds are all linked', function () {
    $outputId = outputUnderOutcome(null);
    $ids = array_map(fn (int $number): int => insertIndicator($outputId, "Indicator {$number}"), range(1, 51));

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(LibraryIndicator::query()->count())->toBe(51)
        ->and(DB::table('indicators')->whereNull('library_indicator_id')->count())->toBe(0)
        ->and(linkedCode($ids[50]))->toBe('HC.OP1.1.51');
});

test('a later indicator joins a deactivated legacy entry with a warning', function () {
    $programId = programWithHrCode('002');
    $outcomeId = insertResult(null, $programId);
    insertIndicator(insertResult($outcomeId, $programId, ResultType::Output), 'Number of people reached');
    $this->artisan('meal:seed-indicator-library')->assertSuccessful();
    LibraryIndicator::query()->sole()->forceFill(['is_active' => false])->save();
    $laterId = insertIndicator(insertResult($outcomeId, $programId, ResultType::Output), 'Number of people reached');

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain('002.OP1.1.1 "Number of people reached" is deactivated')
        ->assertSuccessful();

    expect(linkedCode($laterId))->toBe('002.OP1.1.1')
        ->and(LibraryIndicator::query()->count())->toBe(1);
});

test('results that are each other\'s parents stop the run, naming them', function () {
    $programId = programWithHrCode('002');
    $firstOutputId = insertResult(insertResult(null, $programId), $programId, ResultType::Output);
    $secondOutputId = insertResult($firstOutputId, $programId, ResultType::Output);
    DB::table('results')->where('id', $firstOutputId)->update(['parent_id' => $secondOutputId]);
    $indicatorId = insertIndicator($secondOutputId, 'Number of people reached');

    $this->artisan('meal:seed-indicator-library')
        ->expectsOutputToContain("Results {$firstOutputId}, {$secondOutputId} are each other's parents")
        ->assertFailed();

    expect(LibraryIndicator::query()->count())->toBe(0);
    $this->assertDatabaseHas('indicators', ['id' => $indicatorId, 'library_indicator_id' => null]);
});

test('seeding the library records no activity', function () {
    insertIndicator(outputUnderOutcome(programWithHrCode('002')), 'Number of people reached');
    $activities = Activity::query()->count();

    $this->artisan('meal:seed-indicator-library')->assertSuccessful();

    expect(Activity::query()->count())->toBe($activities);
});
