<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-12 (D-93): a schedule dependency and a baseline belong to a location
     * row, not to a project.
     *
     * Additive only (wave4/AGENT.md §4): the column is nullable and
     * `project_id` is kept and still written until Y-18, so the Gantt and any
     * reader not yet moved keep working. `schedule:row-backfill` fills the
     * rows; both tables are empty on local and production data today, so no
     * tightening migration follows in this task.
     *
     * Pre-checks run before any DDL, since MySQL does not roll DDL back.
     */
    public function up(): void
    {
        $existing = array_values(array_filter(
            ['schedule_dependencies', 'schedule_baselines'],
            fn (string $table): bool => Schema::hasColumn($table, 'proposal_location_id'),
        ));

        if ($existing !== []) {
            throw new RuntimeException('Already has proposal_location_id: '.implode(', ', $existing).'; nothing was changed.');
        }

        Schema::table('schedule_dependencies', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_location_id')->nullable()->after('project_id');
            $table->index('proposal_location_id', 'schedule_dependencies_proposal_location_id_index');
            $table->foreign('proposal_location_id', 'schedule_dependencies_proposal_location_id_foreign')
                ->references('id')->on('proposal_locations')->cascadeOnDelete();
        });

        Schema::table('schedule_baselines', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_location_id')->nullable()->after('project_id');
            $table->index('proposal_location_id', 'schedule_baselines_proposal_location_id_index');
            $table->foreign('proposal_location_id', 'schedule_baselines_proposal_location_id_foreign')
                ->references('id')->on('proposal_locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['schedule_dependencies', 'schedule_baselines'] as $table) {
            if (! Schema::hasColumn($table, 'proposal_location_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign($table.'_proposal_location_id_foreign');
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_proposal_location_id_index');
                $blueprint->dropColumn('proposal_location_id');
            });
        }
    }
};
