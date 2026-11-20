<?php

declare(strict_types=1);

use App\Console\Commands\BackfillPlanIndicatorRows;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-10, step 2 (D-91): one target per indicator per row. Stops before the
     * DDL when the backfill left two targets of one indicator on one row:
     * merging them is a human decision (D-80).
     */
    public function up(): void
    {
        $duplicates = (new BackfillPlanIndicatorRows)->plan()['duplicates'];

        $linked = DB::table('plan_indicators')
            ->whereNotNull('proposal_location_id')
            ->groupBy('indicator_id', 'proposal_location_id')
            ->havingRaw('count(*) > 1')
            ->selectRaw('proposal_location_id, group_concat(id order by id) as ids')
            ->get();

        foreach ($linked as $pair) {
            $duplicates[(int) $pair->proposal_location_id] = array_map(intval(...), explode(',', (string) $pair->ids));
        }

        if ($duplicates !== []) {
            $list = implode('; ', array_map(fn (int $row, array $ids): string => "row {$row}: targets ".implode(', ', $ids), array_keys($duplicates), $duplicates));

            throw new RuntimeException("Two targets of one indicator on one location row; merge them first (targets:row-backfill --dry-run). {$list}");
        }

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->unique(['indicator_id', 'proposal_location_id'], 'plan_indicators_indicator_location_unique');
        });
    }

    public function down(): void
    {
        if (! collect(Schema::getIndexes('plan_indicators'))->contains('name', 'plan_indicators_indicator_location_unique')) {
            return;
        }

        // MySQL drops the FK's own index on indicator_id once the unique index
        // (which starts with it) exists; put it back before dropping the unique.
        if (! collect(Schema::getIndexes('plan_indicators'))->contains('name', 'plan_indicators_indicator_id_foreign')) {
            Schema::table('plan_indicators', function (Blueprint $table) {
                $table->index('indicator_id', 'plan_indicators_indicator_id_foreign');
            });
        }

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropUnique('plan_indicators_indicator_location_unique');
        });
    }
};
