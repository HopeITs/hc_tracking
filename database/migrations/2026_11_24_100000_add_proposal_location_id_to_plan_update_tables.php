<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two tables of a plan's monthly updates and their follow-up tasks,
     * each with the name of its location foreign key.
     *
     * @var array<string, string>
     */
    private const Tables = [
        'plan_updates' => 'plan_updates_location_foreign',
        'activity_plan_follow_up_tasks' => 'apfut_location_foreign',
    ];

    /**
     * Y-15 slice 3, step 1 (D-93): a plan's monthly update and the follow-up
     * task it fires belong to a location row (a "centre" of the plan), not to
     * a project.
     *
     * Additive only (wave4/AGENT.md §4): the column is nullable and nothing is
     * filled here; the next migration backfills it and the third swaps the
     * uniques. `project_id` becomes nullable: it is still written until Y-18,
     * with the row's project, and a row may have none.
     *
     * Pre-check before any DDL, since MySQL does not roll DDL back.
     */
    public function up(): void
    {
        foreach (array_keys(self::Tables) as $table) {
            if (Schema::hasColumn($table, 'proposal_location_id')) {
                throw new RuntimeException("{$table} already has proposal_location_id; nothing was changed.");
            }
        }

        foreach (self::Tables as $table => $foreign) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreign) {
                $blueprint->foreignId('proposal_location_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('proposal_locations', indexName: $foreign)
                    ->cascadeOnDelete();
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('project_id')->nullable()->change();
            });
        }
    }

    /**
     * A row with no project (written after this migration) cannot go back to
     * a required `project_id`, so down() stops before DDL and says which.
     */
    public function down(): void
    {
        foreach (array_keys(self::Tables) as $table) {
            $projectless = Schema::hasColumn($table, 'proposal_location_id')
                ? DB::table($table)->whereNull('project_id')->orderBy('id')->pluck('id')->all()
                : [];

            if ($projectless !== []) {
                throw new RuntimeException("{$table} rows with no project (ids): ".implode(', ', $projectless).'. project_id cannot be required again; nothing was changed.');
            }
        }

        foreach (self::Tables as $table => $foreign) {
            if (! Schema::hasColumn($table, 'proposal_location_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('project_id')->nullable(false)->change();
            });

            Schema::table($table, function (Blueprint $blueprint) use ($foreign) {
                $blueprint->dropForeign($foreign);
                $blueprint->dropColumn('proposal_location_id');
            });
        }
    }
};
