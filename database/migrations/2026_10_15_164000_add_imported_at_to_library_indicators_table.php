<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When `meal:import-framework` last wrote the entry (contracts §11.2). An
     * entry edited by hand afterwards (`updated_at > imported_at`) is left
     * alone by the next import and reported as a conflict.
     */
    public function up(): void
    {
        if (Schema::hasColumn('library_indicators', 'imported_at')) {
            throw new RuntimeException('library_indicators.imported_at already exists; this migration has partly run.');
        }

        Schema::table('library_indicators', function (Blueprint $table) {
            $table->timestamp('imported_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('library_indicators', function (Blueprint $table) {
            $table->dropColumn('imported_at');
        });
    }
};
