<?php

declare(strict_types=1);

use App\Support\Locations\RiskRowBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-13, step 2 (D-93, D-94): fill the column the previous migration added.
     *
     * The rule lives in `RiskRowBackfill`, which `risks:row-backfill` also
     * calls, so history and every later run follow one definition. Risks it
     * cannot map are left alone (D-80) and the command reports their ids.
     *
     * Nothing is tightened here: `proposal_location_id` stays nullable and
     * `project_id` keeps its value until Y-18.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('risks', 'proposal_location_id')) {
            throw new RuntimeException('risks has no proposal_location_id; run the previous migration first.');
        }

        app(RiskRowBackfill::class)->run(apply: true);
    }

    /**
     * Give the column back its emptiness, leaving every other value as it is.
     * Works on a database that already holds backfilled rows, which is the
     * only state this migration can be rolled back from.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('risks', 'proposal_location_id')) {
            return;
        }

        DB::table('risks')->whereNotNull('proposal_location_id')->update(['proposal_location_id' => null]);
    }
};
