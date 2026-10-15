<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an activity runs over time (contracts §11.2, D-51): `continuous`
     * (default) or `event`, on activities only. Activities created before the
     * column existed become continuous. MySQL does not roll back DDL, so a
     * column already present stops the run before anything changes.
     */
    public function up(): void
    {
        if (Schema::hasColumn('results', 'activity_kind')) {
            throw new RuntimeException('results.activity_kind already exists; this migration has partly run.');
        }

        Schema::table('results', function (Blueprint $table) {
            $table->string('activity_kind', 20)->nullable()->after('type');
        });

        DB::table('results')->where('type', 'activity')->update(['activity_kind' => 'continuous']);
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn('activity_kind');
        });
    }
};
