<?php

declare(strict_types=1);

use App\Support\Locations\PlanUpdateRowBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-15 slice 3, step 2 (D-93): fill the columns the previous migration
     * added, on `plan_updates` and `activity_plan_follow_up_tasks`.
     *
     * The rule lives in `PlanUpdateRowBackfill`, which
     * `plan-updates:row-backfill` also calls. A row it cannot map is left
     * alone (D-80); the next migration refuses until none is left.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('plan_updates', 'proposal_location_id') || ! Schema::hasColumn('activity_plan_follow_up_tasks', 'proposal_location_id')) {
            throw new RuntimeException('The plan update tables have no proposal_location_id; run the previous migration first.');
        }

        app(PlanUpdateRowBackfill::class)->run(apply: true);
    }

    /**
     * Give the columns back their emptiness, leaving every other value as it
     * is. Works on a database that already holds backfilled rows.
     */
    public function down(): void
    {
        foreach (['plan_updates', 'activity_plan_follow_up_tasks'] as $table) {
            if (Schema::hasColumn($table, 'proposal_location_id')) {
                DB::table($table)->whereNotNull('proposal_location_id')->update(['proposal_location_id' => null]);
            }
        }
    }
};
