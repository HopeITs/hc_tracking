<?php

declare(strict_types=1);

use App\Console\Commands\PlaceBackfillPlanIndicators;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-08, step 1 (D-75, D-82, D-85, D-88, D-90): a target gets its place
     * (all nullable, so a target without a project has none), then existing
     * targets are backfilled. Step 2 adds the index. Pre-checks run before
     * any DDL: MySQL does not roll DDL back.
     */
    public function up(): void
    {
        foreach (['city_id', 'program_id', 'center_id', 'city_wide', 'all_centers'] as $column) {
            if (Schema::hasColumn('plan_indicators', $column)) {
                throw new RuntimeException("plan_indicators.{$column} already exists; nothing was changed.");
            }
        }

        $backfill = new PlaceBackfillPlanIndicators;
        $plan = $backfill->plan();

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('project_id');
            $table->unsignedBigInteger('program_id')->nullable()->after('city_id');
            $table->unsignedBigInteger('center_id')->nullable()->after('program_id');
            $table->boolean('city_wide')->default(false)->after('center_id');
            $table->boolean('all_centers')->default(false)->after('city_wide');

            $table->foreign('program_id', 'plan_indicators_program_id_foreign')->references('id')->on('programs')->restrictOnDelete();
        });

        $backfill->apply($plan);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plan_indicators', 'city_id')) {
            return;
        }

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropForeign('plan_indicators_program_id_foreign');
        });

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropColumn(['city_id', 'program_id', 'center_id', 'city_wide', 'all_centers']);
        });
    }
};
