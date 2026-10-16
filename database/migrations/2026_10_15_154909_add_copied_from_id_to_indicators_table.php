<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The program-framework indicator a proposal indicator was copied from
     * (contracts §11.2, CopyResultTree). Its default target is read from
     * there; deleting the source keeps the copy.
     */
    public function up(): void
    {
        if (Schema::hasColumn('indicators', 'copied_from_id')) {
            throw new RuntimeException('indicators.copied_from_id already exists; this migration has partly run.');
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->unsignedBigInteger('copied_from_id')->nullable()->after('library_indicator_id');

            $table->foreign('copied_from_id', 'indicators_copied_from_id_foreign')
                ->references('id')->on('indicators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropForeign('indicators_copied_from_id_foreign');
            $table->dropColumn('copied_from_id');
        });
    }
};
