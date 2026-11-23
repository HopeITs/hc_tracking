<?php

declare(strict_types=1);

use App\Support\Locations\ScheduleOverrideRowBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-15 slice 1, step 2 (D-93): fill the column the previous migration
     * added.
     *
     * The rule lives in `ScheduleOverrideRowBackfill`, which
     * `activity-schedules:row-backfill` also calls, so history and every later
     * run follow one definition. An override it cannot map is left alone
     * (D-80); the next migration refuses until none is left.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('activity_schedules', 'proposal_location_id')) {
            throw new RuntimeException('activity_schedules has no proposal_location_id; run the previous migration first.');
        }

        app(ScheduleOverrideRowBackfill::class)->run(apply: true);
    }

    /**
     * Give the column back its emptiness, leaving every other value as it is.
     * Works on a database that already holds backfilled rows.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('activity_schedules', 'proposal_location_id')) {
            return;
        }

        DB::table('activity_schedules')->whereNotNull('proposal_location_id')->update(['proposal_location_id' => null]);
    }
};
