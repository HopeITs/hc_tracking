<?php

declare(strict_types=1);

use App\Support\Locations\ActivityStatusRowBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-15 slice 2, step 2 (D-93): fill the column the previous migration
     * added.
     *
     * The rule lives in `ActivityStatusRowBackfill`, which
     * `activity-statuses:row-backfill` also calls. A status it cannot map is
     * left alone (D-80); the next migration refuses until none is left.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('project_activity_statuses', 'proposal_location_id')) {
            throw new RuntimeException('project_activity_statuses has no proposal_location_id; run the previous migration first.');
        }

        app(ActivityStatusRowBackfill::class)->run(apply: true);
    }

    /**
     * Give the column back its emptiness, leaving every other value as it is.
     * Works on a database that already holds backfilled rows, once the third
     * migration has been rolled back (the column is nullable again).
     */
    public function down(): void
    {
        if (! Schema::hasColumn('project_activity_statuses', 'proposal_location_id')) {
            return;
        }

        DB::table('project_activity_statuses')->whereNotNull('proposal_location_id')->update(['proposal_location_id' => null]);
    }
};
