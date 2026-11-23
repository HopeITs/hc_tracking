<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-13, step 1 (D-93): a register risk belongs to a location row, not to a
     * project (contracts.md §7).
     *
     * Additive only (wave4/AGENT.md §4): the column is nullable and nothing is
     * filled here. The next migration backfills it through
     * `App\Support\Locations\RiskRowBackfill`, and `project_id` keeps being
     * written until Y-18 so every reader not yet moved still works.
     *
     * Pre-check before any DDL, since MySQL does not roll DDL back.
     */
    public function up(): void
    {
        if (Schema::hasColumn('risks', 'proposal_location_id')) {
            throw new RuntimeException('risks already has proposal_location_id; nothing was changed.');
        }

        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('proposal_location_id')
                ->nullable()
                ->after('project_id')
                ->constrained('proposal_locations', indexName: 'risks_proposal_location_id_foreign')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('risks', 'proposal_location_id')) {
            return;
        }

        Schema::table('risks', function (Blueprint $table) {
            $table->dropForeign('risks_proposal_location_id_foreign');
            $table->dropColumn('proposal_location_id');
        });
    }
};
