<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const Foreign = 'indicators_location_foreign';

    /**
     * Y-16 slice 1, step 1 (contracts.md §11, D-93): an indicator instance
     * scoped to one place of a proposal carries that location row.
     *
     * Additive only (wave4/AGENT.md §4): the column is nullable and nothing is
     * filled here; the next migration backfills it. `project_id` is kept and
     * still written until Y-18, so no column changes type here.
     *
     * `nullOnDelete` and not `cascadeOnDelete`: an indicator is a statement of
     * the framework, not a record of the row. Losing the row must not delete
     * the indicator, its targets or their reports — it only unscopes it.
     *
     * Pre-check before any DDL, since MySQL does not roll DDL back.
     */
    public function up(): void
    {
        if (Schema::hasColumn('indicators', 'proposal_location_id')) {
            throw new RuntimeException('indicators already has proposal_location_id; nothing was changed.');
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->foreignId('proposal_location_id')
                ->nullable()
                ->after('project_id')
                ->constrained('proposal_locations', indexName: self::Foreign)
                ->nullOnDelete();
        });
    }

    /**
     * Works on a database that already holds backfilled rows: the column goes
     * and `project_id`, still written, is what readers fall back to.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('indicators', 'proposal_location_id')) {
            return;
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->dropForeign(self::Foreign);
            $table->dropColumn('proposal_location_id');
        });
    }
};
