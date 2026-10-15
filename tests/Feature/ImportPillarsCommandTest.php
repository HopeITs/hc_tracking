<?php

use App\Models\Program;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;

const StrategyPillarsFile = 'database/seeders/data/strategy-2026-2030.json';

/**
 * The HR programs D-35a names that HR has today, by HR id, plus Hope Center
 * (1), which stays without a pillar.
 *
 * @var array<int, string>
 */
const PillarHrPrograms = [
    1 => 'Hope Center',
    2 => 'Micro Project',
    3 => 'Relief Programs',
    4 => 'Family to Family',
    7 => 'Development Impact and Support Center',
    8 => 'Study Zone',
    10 => 'Children University',
    12 => 'Entrepreneurship project',
    13 => 'Leadership Empowerment & Achievement Program',
];

/**
 * Insert the HR programs above under their real ids.
 */
function insertPillarHrPrograms(): void
{
    foreach (PillarHrPrograms as $id => $name) {
        DB::connection('hr')->table('programs')->insert([
            'id' => $id,
            'name' => $name,
            'code' => sprintf('%03d', $id),
            'slug' => "program-{$id}",
            'is_active' => true,
        ]);
    }
}

/**
 * The tracking row of an HR program, created without a strategy.
 */
function trackedProgram(int $id, ?int $strategyId = null, ?int $pillarId = null): void
{
    DB::table('programs')->insert(['id' => $id, 'strategy_id' => $strategyId, 'strategic_pillar_id' => $pillarId, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Each program's pillar code, by HR id.
 *
 * @return array<int, ?string>
 */
function pillarCodes(): array
{
    return Program::query()->with('pillar')->orderBy('id')->get()
        ->mapWithKeys(fn (Program $program): array => [$program->id => $program->pillar?->code])
        ->all();
}

test('programs are assigned to their pillar by HR id and the missing ones are reported', function () {
    insertPillarHrPrograms();
    $strategy = Strategy::factory()->create();
    trackedProgram(2, $strategy->id);

    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => $strategy->id])
        ->expectsOutputToContain('Wells')
        ->expectsOutputToContain('Alternative Energy')
        ->expectsOutputToContain('PSS')
        ->expectsOutputToContain('Case Management')
        ->expectsOutputToContain('Parenting')
        ->assertSuccessful();

    expect($strategy->pillars()->pluck('code')->all())->toBe(['P1', 'P2', 'P3'])
        ->and($strategy->pillars()->first()->name_ar)->toBe('التمكين الاقتصادي ودعم الأسر')
        ->and(pillarCodes())->toBe([1 => null, 2 => 'P1', 3 => 'P1', 4 => 'P1', 7 => 'P2', 8 => 'P2', 10 => 'P3', 12 => 'P2', 13 => 'P2'])
        ->and(Program::query()->whereNotNull('strategic_pillar_id')->where('strategy_id', '!=', $strategy->id)->exists())->toBeFalse();
});

test('the strategy is found by the slug in the file when no option is given', function () {
    insertPillarHrPrograms();
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy 2026-2030']);

    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile])->assertSuccessful();

    expect($strategy->slug)->toBe('country-strategy-2026-2030')
        ->and($strategy->pillars()->count())->toBe(3);
});

test('a program of another strategy is reported and left alone', function () {
    insertPillarHrPrograms();
    $strategy = Strategy::factory()->create();
    trackedProgram(3, Strategy::factory()->create()->id);

    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => $strategy->id])
        ->expectsOutputToContain('Relief Programs')
        ->assertSuccessful();

    expect(pillarCodes()[3])->toBeNull();
});

test('a program already on another pillar is reported and left alone', function () {
    insertPillarHrPrograms();
    $strategy = Strategy::factory()->create();
    $pillar = StrategicPillar::factory()->create(['strategy_id' => $strategy->id, 'code' => 'P9']);
    trackedProgram(8, $strategy->id, $pillar->id);

    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => $strategy->id])
        ->expectsOutputToContain('Study Zone')
        ->assertSuccessful();

    expect(pillarCodes()[8])->toBe('P9');
});

test('a second run changes nothing', function () {
    insertPillarHrPrograms();
    $strategy = Strategy::factory()->create();
    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => $strategy->id])->assertSuccessful();
    $pillars = DB::table('strategic_pillars')->orderBy('id')->get()->toArray();
    $programs = DB::table('programs')->orderBy('id')->get()->toArray();

    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => $strategy->id])->assertSuccessful();

    expect(DB::table('strategic_pillars')->orderBy('id')->get()->toArray())->toEqual($pillars)
        ->and(DB::table('programs')->orderBy('id')->get()->toArray())->toEqual($programs);
});

test('a dry run writes nothing', function () {
    insertPillarHrPrograms();
    $strategy = Strategy::factory()->create();

    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => $strategy->id, '--dry-run' => true])
        ->expectsOutputToContain('P1')
        ->assertSuccessful();

    expect(DB::table('strategic_pillars')->count())->toBe(0)
        ->and(DB::table('programs')->count())->toBe(0);
});

test('an unknown strategy stops the run', function () {
    $this->artisan('strategy:import-pillars', ['file' => StrategyPillarsFile, '--strategy' => 999])->assertFailed();

    expect(DB::table('strategic_pillars')->count())->toBe(0);
});
