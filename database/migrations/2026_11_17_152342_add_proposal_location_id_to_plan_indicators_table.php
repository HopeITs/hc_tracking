<?php

declare(strict_types=1);

use App\Console\Commands\BackfillPlanIndicatorRows;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-10, step 1 (D-90, D-91): a proposal target belongs to a location row.
     * The column is nullable (annual-plan targets have no row, D-44); existing
     * proposal targets are linked and re-placed from their row. Step 2 adds
     * the unique index. Pre-checks run before any DDL: MySQL does not roll
     * DDL back.
     */
    public function up(): void
    {
        if (Schema::hasColumn('plan_indicators', 'proposal_location_id')) {
            throw new RuntimeException('plan_indicators.proposal_location_id already exists; nothing was changed.');
        }

        $backfill = new BackfillPlanIndicatorRows;
        $plan = $backfill->plan();

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_location_id')->nullable()->after('project_id');
            $table->index('proposal_location_id', 'plan_indicators_proposal_location_id_index');
            $table->foreign('proposal_location_id', 'plan_indicators_proposal_location_id_foreign')
                ->references('id')->on('proposal_locations')->restrictOnDelete();
        });

        $backfill->apply($plan);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plan_indicators', 'proposal_location_id')) {
            return;
        }

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropForeign('plan_indicators_proposal_location_id_foreign');
        });

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropIndex('plan_indicators_proposal_location_id_index');
            $table->dropColumn('proposal_location_id');
        });
    }
};
