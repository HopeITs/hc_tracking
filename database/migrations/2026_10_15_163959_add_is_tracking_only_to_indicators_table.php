<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An indicator followed without a target (contracts §12.1, D-37: the
     * logframe's `-`). It stays selectable but never gets a plan_indicators row.
     */
    public function up(): void
    {
        if (Schema::hasColumn('indicators', 'is_tracking_only')) {
            throw new RuntimeException('indicators.is_tracking_only already exists; this migration has partly run.');
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->boolean('is_tracking_only')->default(false)->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn('is_tracking_only');
        });
    }
};
