<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-15 slice 1, step 1 (D-93, D-49): a centre's calendar override belongs
     * to a location row, not to a project (contracts.md §11.2).
     *
     * Additive only (wave4/AGENT.md §4): the column is nullable and nothing is
     * filled here. The next migration backfills it through
     * `App\Support\Locations\ScheduleOverrideRowBackfill`; the third swaps the
     * unique index. `project_id` keeps being written until Y-18.
     *
     * Pre-check before any DDL, since MySQL does not roll DDL back.
     */
    public function up(): void
    {
        if (Schema::hasColumn('activity_schedules', 'proposal_location_id')) {
            throw new RuntimeException('activity_schedules already has proposal_location_id; nothing was changed.');
        }

        Schema::table('activity_schedules', function (Blueprint $table) {
            $table->foreignId('proposal_location_id')
                ->nullable()
                ->after('project_id')
                ->constrained('proposal_locations', indexName: 'activity_schedules_proposal_location_id_foreign')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('activity_schedules', 'proposal_location_id')) {
            return;
        }

        Schema::table('activity_schedules', function (Blueprint $table) {
            $table->dropForeign('activity_schedules_proposal_location_id_foreign');
            $table->dropColumn('proposal_location_id');
        });
    }
};
