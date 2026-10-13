<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns only: the behaviour belongs to the proposal planning task.
     */
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->boolean('is_final_report')->default(false);

            $table->foreign('proposal_id', 'milestones_proposal_id_foreign')
                ->references('id')->on('proposals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropForeign('milestones_proposal_id_foreign');
            $table->dropColumn(['proposal_id', 'is_final_report']);
        });
    }
};
