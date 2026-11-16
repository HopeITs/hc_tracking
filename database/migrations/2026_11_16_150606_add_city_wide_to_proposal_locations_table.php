<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field work, no center (Y-01, D-88): the program runs across the city
     * with no center of its own. A city-wide row holds no center rows.
     */
    public function up(): void
    {
        if (Schema::hasColumn('proposal_locations', 'city_wide')) {
            throw new RuntimeException('proposal_locations.city_wide already exists; nothing was changed.');
        }

        Schema::table('proposal_locations', function (Blueprint $table) {
            $table->boolean('city_wide')->default(false)->after('is_new');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('proposal_locations', 'city_wide')) {
            return;
        }

        Schema::table('proposal_locations', function (Blueprint $table) {
            $table->dropColumn('city_wide');
        });
    }
};
