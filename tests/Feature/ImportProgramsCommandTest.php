<?php

use App\Models\Program;
use Illuminate\Support\Facades\DB;

/**
 * Insert a tracking program row directly, the way every row looked before
 * W-34b: an id mirroring HR and nothing else filled in.
 */
function emptyTrackedProgram(int $id): void
{
    DB::table('programs')->insert(['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * @return array<string, mixed>
 */
function programRow(int $id): array
{
    return (array) DB::table('programs')->where('id', $id)->first();
}

test('a dry run reports what it would change and writes nothing', function () {
    $hopeId = insertHrProgram('Hope Center', code: '001');
    emptyTrackedProgram($hopeId);

    $this->artisan('programs:import-from-hr')
        ->expectsOutputToContain('Hope Center')
        ->assertExitCode(0);

    expect(programRow($hopeId)['name_en'])->toBe('')
        ->and(programRow($hopeId)['slug'])->toBeNull();
});

test('apply fills the name, code, slug and active flag from HR', function () {
    $hopeId = insertHrProgram('Hope Center', code: '001');
    $slug = DB::connection('hr')->table('programs')->where('id', $hopeId)->value('slug');
    $retiredId = insertHrProgram('CYG', isActive: false, code: '009');
    emptyTrackedProgram($hopeId);
    emptyTrackedProgram($retiredId);

    $this->artisan('programs:import-from-hr', ['--apply' => true])->assertExitCode(0);

    $hope = programRow($hopeId);

    expect($hope['name_en'])->toBe('Hope Center')
        ->and($hope['name_ar'])->toBeNull()
        ->and($hope['code'])->toBe('001')
        ->and($hope['slug'])->toBe($slug)
        ->and((bool) $hope['is_active'])->toBeTrue()
        ->and((bool) programRow($retiredId)['is_active'])->toBeFalse();
});

test('apply inserts a local row for an HR program tracking does not have', function () {
    $hopeId = insertHrProgram('Hope Center', code: '001');

    $this->artisan('programs:import-from-hr', ['--apply' => true])->assertExitCode(0);

    expect(programRow($hopeId)['name_en'])->toBe('Hope Center');
});

test('a second apply changes nothing', function () {
    $hopeId = insertHrProgram('Hope Center', code: '001');
    emptyTrackedProgram($hopeId);

    $this->artisan('programs:import-from-hr', ['--apply' => true])->assertExitCode(0);
    $after = programRow($hopeId);

    $this->artisan('programs:import-from-hr', ['--apply' => true])
        ->expectsOutputToContain('0')
        ->assertExitCode(0);

    expect(programRow($hopeId))->toBe($after);
});

test('a name edited in tracking survives an apply and only --force overwrites it', function () {
    $hopeId = insertHrProgram('Hope Center', code: '001');
    emptyTrackedProgram($hopeId);
    DB::table('programs')->where('id', $hopeId)->update(['name_en' => 'Hope Center Aleppo']);

    $this->artisan('programs:import-from-hr', ['--apply' => true])->assertExitCode(0);

    expect(programRow($hopeId)['name_en'])->toBe('Hope Center Aleppo');

    $this->artisan('programs:import-from-hr', ['--apply' => true, '--force' => true])->assertExitCode(0);

    expect(programRow($hopeId)['name_en'])->toBe('Hope Center');
});

test('the drift report names programs HR no longer has', function () {
    insertHrProgram('Hope Center', code: '001');
    DB::table('programs')->insert(['id' => 4242, 'name_en' => 'Wells', 'code' => '042', 'slug' => 'wells', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('programs:import-from-hr')
        ->expectsOutputToContain('Wells')
        ->assertExitCode(0);
});

test('a program created after the import gets an id clear of the HR range', function () {
    $hopeId = insertHrProgram('Hope Center', code: '001');
    emptyTrackedProgram($hopeId);

    $this->artisan('programs:import-from-hr', ['--apply' => true])->assertExitCode(0);

    $program = new Program;
    $program->name_en = 'Wells';
    $program->code = '042';
    $program->save();

    expect($program->id)->toBeGreaterThan($hopeId + 999);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'AUTO_INCREMENT is raised on MySQL only.');
