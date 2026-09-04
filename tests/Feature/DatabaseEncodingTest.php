<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns backed by a `json_valid()` CHECK constraint, keyed as
 * `table.column`. MariaDB implements Laravel's `$table->json()` as
 * `longtext ... COLLATE utf8mb4_bin`, so those columns are a deliberate
 * exception to the utf8mb4_unicode_ci rule and must never be "corrected".
 *
 * @return array<int, string>
 */
function jsonColumns(): array
{
    return DB::table('information_schema.CHECK_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->where('CHECK_CLAUSE', 'like', 'json_valid(%')
        ->get()
        ->map(fn (object $constraint): string => "{$constraint->TABLE_NAME}.{$constraint->CONSTRAINT_NAME}")
        ->all();
}

test('the active connection is configured for utf8mb4 and utf8mb4_unicode_ci', function () {
    $connection = config('database.default');

    expect(config("database.connections.{$connection}.charset"))->toBe('utf8mb4')
        ->and(config("database.connections.{$connection}.collation"))->toBe('utf8mb4_unicode_ci')
        ->and(config("database.connections.{$connection}.engine"))->toBe('InnoDB ROW_FORMAT=DYNAMIC');
});

test('generated create table statements pin the encoding and storage engine', function () {
    $blueprint = new Blueprint(Schema::getConnection(), 'encoding_probe');
    $blueprint->create();
    $blueprint->id();

    $statements = $blueprint->toSql();

    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('default character set utf8mb4')
        ->and($statements[0])->toContain("collate 'utf8mb4_unicode_ci'")
        ->and($statements[0])->toContain('engine = InnoDB ROW_FORMAT=DYNAMIC');
});

test('every table is InnoDB, dynamic row format and utf8mb4_unicode_ci', function () {
    $tables = DB::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->orderBy('TABLE_NAME')
        ->get();

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $name = $table->TABLE_NAME;

        expect($table->ENGINE)->toBe('InnoDB', "Table [{$name}] does not use the InnoDB engine.")
            ->and($table->ROW_FORMAT)->toBe('Dynamic', "Table [{$name}] does not use the DYNAMIC row format.")
            ->and($table->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci', "Table [{$name}] is not utf8mb4_unicode_ci.");
    }
});

test('every text column is utf8mb4, with utf8mb4_bin reserved for json columns', function () {
    $jsonColumns = jsonColumns();

    $columns = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->whereNotNull('COLLATION_NAME')
        ->orderBy('TABLE_NAME')
        ->orderBy('COLUMN_NAME')
        ->get();

    expect($columns)->not->toBeEmpty()
        ->and($jsonColumns)->toContain('passkeys.credential');

    foreach ($columns as $column) {
        $name = "{$column->TABLE_NAME}.{$column->COLUMN_NAME}";
        $expected = in_array($name, $jsonColumns, true) ? 'utf8mb4_bin' : 'utf8mb4_unicode_ci';

        expect($column->CHARACTER_SET_NAME)->toBe('utf8mb4', "Column [{$name}] is not utf8mb4.")
            ->and($column->COLLATION_NAME)->toBe($expected, "Column [{$name}] is not {$expected}.");
    }
});

test('four byte and non latin characters survive a write and read', function () {
    $name = 'مركز الرعاية 🏥 — Ünïcodé';

    $user = User::factory()->create(['name' => $name]);

    expect(User::query()->findOrFail($user->id)->name)->toBe($name)
        ->and(DB::table('users')->where('id', $user->id)->value('name'))->toBe($name);
});
