<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The strategic pillar a program sits under (contracts §11.2, D-35): at
     * most one. Deleting the pillar leaves the program without one.
     */
    public function up(): void
    {
        if (Schema::hasColumn('programs', 'strategic_pillar_id')) {
            throw new RuntimeException('programs.strategic_pillar_id already exists; this migration has partly run.');
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->unsignedBigInteger('strategic_pillar_id')->nullable()->after('strategy_id');

            $table->foreign('strategic_pillar_id', 'programs_strategic_pillar_id_foreign')
                ->references('id')->on('strategic_pillars')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropForeign('programs_strategic_pillar_id_foreign');
            $table->dropColumn('strategic_pillar_id');
        });
    }
};
