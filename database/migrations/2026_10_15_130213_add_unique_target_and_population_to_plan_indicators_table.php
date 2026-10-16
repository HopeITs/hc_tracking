<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One target per (source, indicator, project) and the display base of a
     * percentage target (contracts §12.1, T13). MySQL does not roll back DDL,
     * so duplicates or a column already present stop the run before anything
     * changes. Rows with a NULL project are not covered by the index.
     */
    public function up(): void
    {
        if (Schema::hasColumn('plan_indicators', 'target_population')) {
            throw new RuntimeException('plan_indicators.target_population already exists; this migration has partly run.');
        }

        $duplicates = DB::table('plan_indicators')
            ->selectRaw('GROUP_CONCAT(id ORDER BY id) as ids')
            ->groupBy('source_type', 'source_id', 'indicator_id', 'project_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ids');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate targets on (source_type, source_id, indicator_id, project_id), plan_indicators ids: '.$duplicates->implode('; ').'. Resolve them, then run this again.');
        }

        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->decimal('target_population', 12, 2)->nullable()->after('target');
            $table->unique(['source_type', 'source_id', 'indicator_id', 'project_id'], 'plan_indicators_source_indicator_project_unique');
        });
    }

    public function down(): void
    {
        Schema::table('plan_indicators', function (Blueprint $table) {
            $table->dropUnique('plan_indicators_source_indicator_project_unique');
            $table->dropColumn('target_population');
        });
    }
};
