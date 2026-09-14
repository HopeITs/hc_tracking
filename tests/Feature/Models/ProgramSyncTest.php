<?php

use App\Http\Middleware\SyncProgramsFromHr;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

test('syncing mirrors every hr program under its hr id, inactive ones included', function () {
    $hopeId = insertHrProgram('Hope Center');
    $cygId = insertHrProgram('CYG', isActive: false);

    Program::syncFromHr();

    expect(Program::query()->orderBy('id')->pluck('name', 'id')->all())->toBe([
        $hopeId => 'Hope Center',
        $cygId => 'CYG',
    ])->and(Program::query()->whereNotNull('strategy_id')->orWhereNotNull('manager_id')->count())->toBe(0);
});

test('syncing refreshes a renamed program but keeps its strategy and manager', function () {
    $program = Program::factory()->create(['name' => 'Hope Center']);
    $strategyId = $program->strategy_id;
    $managerId = $program->manager_id;
    DB::connection('hr')->table('programs')->where('id', $program->id)->update(['name' => 'Hope Centre']);

    Program::syncFromHr();

    expect($program->refresh())
        ->name->toBe('Hope Centre')
        ->strategy_id->toBe($strategyId)
        ->manager_id->toBe($managerId);
});

test('a program removed from hr keeps its mirror and the delivery under it', function () {
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id]);
    DB::connection('hr')->table('programs')->where('id', $program->id)->delete();

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

test('a program points at the hr program it mirrors', function () {
    $program = Program::factory()->create(['name' => 'Sha8er']);

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
    $middleware = new SyncProgramsFromHr;
    $next = fn (): Response => new Response;

    insertHrProgram('First');
    $middleware->handle(Request::create('/'), $next);
    insertHrProgram('Second');
    $middleware->handle(Request::create('/'), $next);

    expect(Program::query()->pluck('name')->all())->toBe(['First']);

    $this->travel(61)->seconds();
    $middleware->handle(Request::create('/'), $next);

    expect(Program::query()->count())->toBe(2);
});

test('an unreachable hr database does not break the request and retries next time', function () {
    Cache::forget(SyncProgramsFromHr::CacheKey);
    config(['database.connections.hr.database' => '/missing/dir/hr.sqlite']);
    DB::purge('hr');

    $response = (new SyncProgramsFromHr)->handle(Request::create('/'), fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and(Cache::has(SyncProgramsFromHr::CacheKey))->toBeFalse();
});
