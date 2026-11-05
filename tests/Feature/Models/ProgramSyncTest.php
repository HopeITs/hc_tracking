<?php

use App\Http\Middleware\SyncProgramsFromHr;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Since W-34b (D-68) tracking owns the programs. The sync that used to mirror
 * HR's table creates nothing any more: all it still does is follow HR's
 * Program Manager position, because a manager is an HR employee.
 */
test('syncing creates no program, however many HR has', function () {
    insertHrProgram('Hope Center');
    insertHrProgram('CYG', isActive: false);

    Program::syncFromHr();

    expect(Program::query()->count())->toBe(0);
});

test('a program HR has never seen is untouched by a sync', function () {
    $program = Program::factory()->create(['name_en' => 'Wells']);

    Program::syncFromHr();

    expect($program->refresh())->name->toBe('Wells')->is_active->toBeTrue();
});

test('syncing mirrors the hr program manager, including a change of holder or a vacated seat', function () {
    $department = insertHrDepartment();
    $changedId = insertHrProgram('Study Zone');
    $vacatedId = insertHrProgram('Sha8er');
    $untouchedId = insertHrProgram('Hope Center');
    $newManager = insertHrEmployee();
    $oldManager = insertHrEmployee(firstNameEn: 'Lina');
    $local = insertHrEmployee(firstNameEn: 'Omar');
    insertHrContract($newManager, insertHrPosition($department, 'Program Manager', $changedId));
    insertHrPosition($department, 'Program Manager', $vacatedId);
    foreach ([$changedId => $oldManager, $vacatedId => $oldManager, $untouchedId => $local] as $id => $manager) {
        DB::table('programs')->insert(['id' => $id, 'manager_id' => $manager, 'created_at' => now(), 'updated_at' => now()]);
    }

    Program::syncFromHr();

    expect(Program::query()->pluck('manager_id', 'id')->all())->toEqual([
        $changedId => $newManager,
        $vacatedId => null,
        $untouchedId => $local,
    ]);
});

test('a renamed HR program does not rename ours: the name is tracking-owned', function () {
    $program = Program::factory()->create(['name_en' => 'Hope Center']);
    insertHrProgram('Hope Centre');

    Program::syncFromHr();

    expect($program->refresh())->name->toBe('Hope Center');
});

test('a program removed from hr keeps the delivery under it', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id]);

    Program::syncFromHr();

    expect(Program::query()->find($program->id))->not->toBeNull()
        ->and(Project::query()->where('program_id', $program->id)->count())->toBe(1);
});

test('syncing never writes to hr', function () {
    insertHrProgram('Study Zone');
    $before = DB::connection('hr')->table('programs')->get()->toArray();

    Program::syncFromHr();

    expect(DB::connection('hr')->table('programs')->get()->toArray())->toEqual($before);
});

test('a program can still reach HR’s copy of it, for the import and the drift report', function () {
    $hrId = insertHrProgram('Sha8er');
    $program = Program::factory()->create(['id' => $hrId, 'name_en' => 'Sha8er']);

    expect($program->hrProgram)->toBeInstanceOf(HrProgram::class)
        ->and($program->hrProgram->name)->toBe('Sha8er');
});

test('an unassigned program has no strategy or manager', function () {
    $program = Program::factory()->unassigned()->create();

    expect($program->strategy)->toBeNull()
        ->and($program->manager)->toBeNull();
});

test('the sync middleware runs at most once a minute', function () {
    Cache::forget(SyncProgramsFromHr::CacheKey);
    $department = insertHrDepartment();
    $first = insertHrEmployee();
    $second = insertHrEmployee(firstNameEn: 'Lina');
    $program = Program::factory()->unassigned()->create();
    $position = insertHrPosition($department, 'Program Manager', $program->id);
    insertHrContract($first, $position);

    $middleware = new SyncProgramsFromHr;
    $next = fn (): Response => new Response;

    $middleware->handle(Request::create('/'), $next);

    expect($program->refresh()->manager_id)->toBe($first);

    DB::connection('hr')->table('contracts')->where('position_id', $position)->update(['employee_id' => $second]);
    $middleware->handle(Request::create('/'), $next);

    expect($program->refresh()->manager_id)->toBe($first);

    $this->travel(61)->seconds();
    $middleware->handle(Request::create('/'), $next);

    expect($program->refresh()->manager_id)->toBe($second);
});

test('an unreachable hr database does not break the request and retries next time', function () {
    Cache::forget(SyncProgramsFromHr::CacheKey);
    config(['database.connections.hr.database' => '/missing/dir/hr.sqlite']);
    DB::purge('hr');

    $response = (new SyncProgramsFromHr)->handle(Request::create('/'), fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and(Cache::has(SyncProgramsFromHr::CacheKey))->toBeFalse();
});
