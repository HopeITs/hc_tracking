<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-15 slice 2, step 1 (D-93, D-51): a confirmed activity status belongs
     * to a location row, not to a project (contracts.md §13).
     *
     * Additive only (wave4/AGENT.md §4): the column is nullable and nothing is
     * filled here; the next migration backfills it and the third tightens it.
     * `project_id` becomes nullable: it is still written until Y-18, with the
     * row's project, and a row (or a report written after Y-03) may have none.
     *
     * Pre-check before any DDL, since MySQL does not roll DDL back.
     */
    public function up(): void
    {
        if (Schema::hasColumn('project_activity_statuses', 'proposal_location_id')) {
            throw new RuntimeException('project_activity_statuses already has proposal_location_id; nothing was changed.');
        }

        Schema::table('project_activity_statuses', function (Blueprint $table) {
            $table->foreignId('proposal_location_id')
                ->nullable()
                ->after('project_id')
                ->constrained('proposal_locations', indexName: 'project_activity_statuses_location_foreign')
                ->cascadeOnDelete();
        });

        Schema::table('project_activity_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });
    }

    /**
     * A status with no project (written after this migration) cannot go
     * back to a required `project_id`, so down() stops before DDL and says
     * which.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('project_activity_statuses', 'proposal_location_id')) {
            return;
        }

        $projectless = DB::table('project_activity_statuses')->whereNull('project_id')->orderBy('id')->pluck('id')->all();

        if ($projectless !== []) {
            throw new RuntimeException('Activity statuses with no project (ids): '.implode(', ', $projectless).'. project_id cannot be required again; nothing was changed.');
        }

        Schema::table('project_activity_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });

        Schema::table('project_activity_statuses', function (Blueprint $table) {
            $table->dropForeign('project_activity_statuses_location_foreign');
            $table->dropColumn('proposal_location_id');
        });
    }
};
