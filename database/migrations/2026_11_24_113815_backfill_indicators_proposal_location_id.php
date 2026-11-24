<?php

declare(strict_types=1);

use App\Support\Locations\IndicatorRowBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-16 slice 1, step 2 (D-93, D-94): fill the column the previous
     * migration added.
     *
     * The rule lives in `IndicatorRowBackfill`, which
     * `indicators:row-backfill` also calls. An instance it cannot map is left
     * alone (D-80); nothing later refuses on it, because `project_id` is still
     * written and an unscoped instance is a valid state.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('indicators', 'proposal_location_id')) {
            throw new RuntimeException('indicators has no proposal_location_id; run the previous migration first.');
        }

        app(IndicatorRowBackfill::class)->run(apply: true);
    }

    /**
     * Give the column back its emptiness, leaving every other value as it is.
     * Works on a database that already holds backfilled rows.
     */
    public function down(): void
    {
        if (Schema::hasColumn('indicators', 'proposal_location_id')) {
            DB::table('indicators')->whereNotNull('proposal_location_id')->update(['proposal_location_id' => null]);
        }
    }
};
