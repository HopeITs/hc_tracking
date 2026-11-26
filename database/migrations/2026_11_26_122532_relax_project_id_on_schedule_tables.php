<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-17, the escalation Dev 1 answered: `schedule_baselines.project_id`
     * and `schedule_dependencies.project_id` were the only two legacy
     * `project_id` columns still `NOT NULL`.
     *
     * From Y-17 the location row is the only thing written and nothing
     * creates a project, so a schedule baseline or a dependency on a row
     * with no project behind it has nothing to put there. Both columns were
     * made nullable so the record can be written with `project_id` null,
     * exactly as `risks`, `plan_indicators`, `activity_schedules`,
     * `project_activity_statuses`, `plan_updates`, `report_templates`,
     * `monthly_reports` and `indicators` already are.
     *
     * The column, its index and its foreign key are kept: Y-18 drops them
     * with the table. The change is additive in the sense of
     * `wave4/AGENT.md §4` — it only widens what the column accepts, writes
     * no data and loses none.
     *
     * @var list<array{table: string, foreign: string}>
     */
    private const Columns = [
        ['table' => 'schedule_baselines', 'foreign' => 'schedule_baselines_project_id_foreign'],
        ['table' => 'schedule_dependencies', 'foreign' => 'schedule_dependencies_project_id_foreign'],
    ];

    public function up(): void
    {
        // Pre-checks before any DDL: MySQL does not roll a DDL statement back.
        foreach (self::Columns as ['table' => $table]) {
            if (! Schema::hasColumn($table, 'project_id')) {
                throw new RuntimeException("{$table}.project_id is missing; nothing was changed.");
            }
        }

        foreach (self::Columns as ['table' => $table, 'foreign' => $foreign]) {
            if ($this->isNullable($table)) {
                continue;
            }

            // The foreign key holds the column, so it is dropped and put back
            // around the type change.
            Schema::table($table, fn ($blueprint) => $blueprint->dropForeign($foreign));
            DB::statement("ALTER TABLE `{$table}` MODIFY `project_id` BIGINT UNSIGNED NULL");
            Schema::table($table, fn ($blueprint) => $blueprint->foreign('project_id', $foreign)->references('id')->on('projects')->cascadeOnDelete());
        }
    }

    /**
     * Narrow the columns again. A row holding null cannot go back, so it is
     * reported and nothing is changed — never guessed (D-80).
     */
    public function down(): void
    {
        foreach (self::Columns as ['table' => $table]) {
            $orphans = DB::table($table)->whereNull('project_id')->count();

            if ($orphans > 0) {
                throw new RuntimeException("{$table} holds {$orphans} row(s) with no project; nothing was changed.");
            }
        }

        foreach (self::Columns as ['table' => $table, 'foreign' => $foreign]) {
            if (! $this->isNullable($table)) {
                continue;
            }

            Schema::table($table, fn ($blueprint) => $blueprint->dropForeign($foreign));
            DB::statement("ALTER TABLE `{$table}` MODIFY `project_id` BIGINT UNSIGNED NOT NULL");
            Schema::table($table, fn ($blueprint) => $blueprint->foreign('project_id', $foreign)->references('id')->on('projects')->cascadeOnDelete());
        }
    }

    /**
     * Read from the schema rather than guessed, so both directions work on a
     * database in either state.
     */
    private function isNullable(string $table): bool
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === 'project_id') {
                return $column['nullable'];
            }
        }

        return false;
    }
};
