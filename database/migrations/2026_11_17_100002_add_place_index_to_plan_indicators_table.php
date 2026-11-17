<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-08, step 2: the place indexes. The columns stay nullable, a target
     * without a project has no place. Not unique: targets share a place.
     */
    public function up(): void
    {
        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->index('city_id', 'plan_indicators_city_id_index');
            $table->index('center_id', 'plan_indicators_center_id_index');
            $table->index(['city_id', 'program_id', 'center_id', 'city_wide'], 'plan_indicators_place_index');
        });
    }

    public function down(): void
    {
        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropIndex('plan_indicators_place_index');
            $table->dropIndex('plan_indicators_center_id_index');
            $table->dropIndex('plan_indicators_city_id_index');
        });
    }
};
