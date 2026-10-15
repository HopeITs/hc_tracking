<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The framework activity a milestone delivers (contracts §11.2). Deleting
     * the activity unlinks the milestone rather than deleting it.
     */
    public function up(): void
    {
        if (Schema::hasColumn('milestones', 'result_id')) {
            throw new RuntimeException('milestones.result_id already exists; this migration has partly run.');
        }

        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedBigInteger('result_id')->nullable()->after('proposal_id');

            $table->foreign('result_id', 'milestones_result_id_foreign')
                ->references('id')->on('results')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropForeign('milestones_result_id_foreign');
            $table->dropColumn('result_id');
        });
    }
};
