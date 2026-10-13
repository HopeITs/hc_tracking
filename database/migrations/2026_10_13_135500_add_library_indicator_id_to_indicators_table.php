<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an indicator to its Indicator Library entry (contracts.md §11.1).
 *
 * The foreign key restricts: an entry in use is deactivated, never deleted.
 * `code` is a read-only copy of the library code, written when an indicator
 * is attached (T13) or linked by meal:seed-indicator-library.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('indicators', 'library_indicator_id')) {
            return;
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->foreignId('library_indicator_id')
                ->nullable()
                ->after('result_id')
                ->constrained('library_indicators', indexName: 'indicators_library_indicator_id_foreign')
                ->restrictOnDelete();
            $table->string('code', 50)->nullable()->after('library_indicator_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('indicators', 'library_indicator_id')) {
            return;
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->dropForeign('indicators_library_indicator_id_foreign');
            $table->dropColumn(['library_indicator_id', 'code']);
        });
    }
};
