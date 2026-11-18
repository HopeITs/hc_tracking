<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->originalConnection = DB::getDefaultConnection();
    $this->originalSchema = Schema::getFacadeRoot();

    config(['database.connections.legacy_milestones_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('legacy_milestones_test');
    Schema::swap(DB::connection()->getSchemaBuilder());

    Schema::create('milestones', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('project_id')->nullable();
        $table->unsignedBigInteger('proposal_id')->nullable();
    });

    Schema::create('tasks', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->restrictOnDelete();
        $table->string('source_type');
        $table->unsignedBigInteger('source_id');
    });

    foreach ([
        'media' => ['mediable_type'],
        'schedule_dependencies' => ['predecessor_type', 'successor_type'],
        'schedule_baseline_items' => ['item_type'],
        'activities' => ['subject_type'],
        'notifications' => ['subject_type'],
        'notification_alert_ledger' => ['subject_type'],
        'assignments' => ['assignable_type'],
        'permissions' => ['name', 'guard_name'],
    ] as $name => $columns) {
        Schema::create($name, function (Blueprint $table) use ($columns): void {
            $table->id();
            foreach ($columns as $column) {
                $table->string($column);
            }
        });
    }

    $this->migration = require database_path('migrations/2026_11_17_104452_drop_milestones_table.php');
});

afterEach(function () {
    Schema::swap($this->originalSchema);
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('legacy_milestones_test');
});

test('the migration moves tasks and preserves their hierarchy before dropping the legacy milestones', function () {
    DB::table('milestones')->insert([
        ['id' => 1, 'project_id' => 33, 'proposal_id' => 801],
        ['id' => 2, 'project_id' => null, 'proposal_id' => 802],
    ]);
    DB::table('tasks')->insert([
        ['id' => 1, 'parent_task_id' => null, 'source_type' => 'milestone', 'source_id' => 1],
        ['id' => 2, 'parent_task_id' => 1, 'source_type' => 'App\\Models\\Milestone', 'source_id' => 1],
        ['id' => 3, 'parent_task_id' => null, 'source_type' => 'milestone', 'source_id' => 2],
    ]);
    DB::table('media')->insert([['mediable_type' => 'milestone'], ['mediable_type' => 'project']]);
    DB::table('permissions')->insert([
        ['name' => 'milestones.view', 'guard_name' => 'web'],
        ['name' => 'tasks.view', 'guard_name' => 'web'],
    ]);

    $this->migration->up();

    expect(Schema::hasTable('milestones'))->toBeFalse();
    $this->assertDatabaseHas('tasks', ['id' => 1, 'source_type' => 'project', 'source_id' => 33]);
    $this->assertDatabaseHas('tasks', ['id' => 2, 'parent_task_id' => 1, 'source_type' => 'project', 'source_id' => 33]);
    $this->assertDatabaseHas('tasks', ['id' => 3, 'source_type' => 'proposal', 'source_id' => 802]);
    $this->assertDatabaseMissing('media', ['mediable_type' => 'milestone']);
    $this->assertDatabaseHas('media', ['mediable_type' => 'project']);
    $this->assertDatabaseMissing('permissions', ['name' => 'milestones.view']);
    $this->assertDatabaseHas('permissions', ['name' => 'tasks.view']);
});

test('the migration stops before moving any tasks if a task cannot be mapped to a project or proposal', function (bool $milestoneExists) {
    DB::table('milestones')->insert(['id' => 1, 'project_id' => 33]);
    if ($milestoneExists) {
        DB::table('milestones')->insert(['id' => 2]);
    }
    DB::table('tasks')->insert([
        ['id' => 1, 'source_type' => 'milestone', 'source_id' => 1],
        ['id' => 2, 'source_type' => 'milestone', 'source_id' => 2],
    ]);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, 'Task IDs: 2');

    expect(Schema::hasTable('milestones'))->toBeTrue();
    $this->assertDatabaseHas('tasks', ['id' => 1, 'source_type' => 'milestone', 'source_id' => 1]);
    $this->assertDatabaseHas('tasks', ['id' => 2, 'source_type' => 'milestone', 'source_id' => 2]);
})->with(['missing milestone' => false, 'milestone without an owner' => true]);
