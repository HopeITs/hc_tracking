<?php

use App\Enums\ProjectStatus;
use App\Models\Program;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->department = insertHrDepartment();
    $this->idlib = insertHrCity('Idlib', 'إدلب');
    $this->program = Program::factory()->create();
    $this->idlibCenter = insertHrCenter($this->idlib, 'Idlib Center');
    insertHrPosition($this->department, programId: $this->program->id, centerId: $this->idlibCenter);
});

/**
 * A project in a city for a program, not yet mapped to a center.
 */
function unmappedProject(int $cityId, int $programId, string $name, ProjectStatus $status = ProjectStatus::Active): Project
{
    return Project::factory()->create(['name' => $name, 'city_id' => $cityId, 'program_id' => $programId, 'center_id' => null, 'status' => $status]);
}

test('a dry run classifies projects as matched, ambiguous or without a center and writes nothing', function () {
    $matched = unmappedProject($this->idlib, $this->program->id, 'Idlib Micro');

    $aleppo = insertHrCity('Aleppo');
    $aleppoProgram = Program::factory()->create();
    foreach (['Aleppo East', 'Aleppo West'] as $name) {
        insertHrPosition($this->department, programId: $aleppoProgram->id, centerId: insertHrCenter($aleppo, $name));
    }
    unmappedProject($aleppo, $aleppoProgram->id, 'Aleppo Relief');

    unmappedProject(insertHrCity('Raqqa'), $this->program->id, 'Raqqa Wells');

    $this->artisan('hr:map-project-centers')
        ->expectsOutputToContain('Dry run')
        ->expectsTable(['Project', 'Program', 'City', 'Center'], [['#'.$matched->id.' Idlib Micro', $this->program->name, 'Idlib', 'Idlib Center']])
        ->expectsOutputToContain('Aleppo East, Aleppo West')
        ->expectsOutputToContain('Raqqa Wells')
        ->assertSuccessful();

    expect(Project::query()->whereNotNull('center_id')->count())->toBe(0);
});

test('apply fills only unmapped projects and a re-run changes nothing', function () {
    $project = unmappedProject($this->idlib, $this->program->id, 'Idlib Micro');
    $otherCenter = insertHrCenter($this->idlib, 'Hand-picked Center');
    $alreadyMapped = Project::factory()->create(['city_id' => $this->idlib, 'program_id' => $this->program->id, 'center_id' => $otherCenter, 'status' => ProjectStatus::Closed]);

    $this->artisan('hr:map-project-centers --apply')->assertSuccessful();

    expect($project->fresh()->center_id)->toBe($this->idlibCenter)
        ->and($alreadyMapped->fresh()->center_id)->toBe($otherCenter);

    $before = DB::table('projects')->orderBy('id')->get(['id', 'center_id', 'updated_at'])->toArray();

    $this->artisan('hr:map-project-centers --apply')
        ->expectsOutputToContain('Updated 0 project(s)')
        ->assertSuccessful();

    expect(DB::table('projects')->orderBy('id')->get(['id', 'center_id', 'updated_at'])->toArray())->toEqual($before);
});

test('force overwrites a center already set', function () {
    $project = Project::factory()->create(['city_id' => $this->idlib, 'program_id' => $this->program->id, 'center_id' => insertHrCenter($this->idlib, 'Wrong Center')]);

    $this->artisan('hr:map-project-centers --apply --force')->assertSuccessful();

    expect($project->fresh()->center_id)->toBe($this->idlibCenter);
});

test('two open projects that would share a center and program are reported and left alone', function () {
    $first = unmappedProject($this->idlib, $this->program->id, 'Idlib Micro A');
    $second = unmappedProject($this->idlib, $this->program->id, 'Idlib Micro B', ProjectStatus::Draft);

    $this->artisan('hr:map-project-centers --apply')
        ->expectsOutputToContain('Idlib Micro A')
        ->expectsOutputToContain('Idlib Micro B')
        ->assertSuccessful();

    expect($first->fresh()->center_id)->toBeNull()
        ->and($second->fresh()->center_id)->toBeNull();
});

test('inactive centers are never proposed', function () {
    $raqqa = insertHrCity('Raqqa');
    insertHrPosition($this->department, programId: $this->program->id, centerId: insertHrCenter($raqqa, 'Closed Raqqa', isActive: false));
    $project = unmappedProject($raqqa, $this->program->id, 'Raqqa Micro');

    $this->artisan('hr:map-project-centers --apply')->assertSuccessful();

    expect($project->fresh()->center_id)->toBeNull();
});

test('the report lists duplicated program codes and the pillar programs HR must add', function () {
    foreach (['003' => ['Micro', 'Relief'], '010' => ['Wells']] as $code => $names) {
        foreach ($names as $name) {
            DB::connection('hr')->table('programs')->insert(['name' => $name, 'code' => $code, 'slug' => fake()->unique()->slug(), 'is_active' => true]);
        }
    }

    $this->artisan('hr:map-project-centers')
        ->expectsOutputToContain('Duplicated program codes')
        ->expectsOutputToContain('Micro, Relief')
        ->expectsTable(['Program (D-35a)', 'In HR', 'Code'], [
            ['Wells', 'yes', '010'],
            ['Alternative Energy', 'no', '—'],
            ['PSS', 'no', '—'],
            ['Case Management', 'no', '—'],
            ['Parenting', 'no', '—'],
        ])
        ->assertSuccessful();
});
