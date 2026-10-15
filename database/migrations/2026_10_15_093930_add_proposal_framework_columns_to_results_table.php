<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns only (contracts §11.2): every existing row stays in the program
     * framework (proposal_id NULL). MySQL does not roll back DDL, so a column
     * already present stops the run before anything changes.
     */
    public function up(): void
    {
        foreach (['proposal_id', 'contributes_to_result_id', 'copied_from_id'] as $column) {
            if (Schema::hasColumn('results', $column)) {
                throw new RuntimeException("results.{$column} already exists; this migration has partly run.");
            }
        }

        Schema::table('results', function (Blueprint $table) {
            $table->unsignedBigInteger('proposal_id')->nullable()->after('program_id');
            $table->unsignedBigInteger('contributes_to_result_id')->nullable()->after('proposal_id');
            $table->unsignedBigInteger('copied_from_id')->nullable()->after('contributes_to_result_id');

            $table->foreign('proposal_id', 'results_proposal_id_foreign')
                ->references('id')->on('proposals')->restrictOnDelete();
            $table->foreign('contributes_to_result_id', 'results_contributes_to_result_id_foreign')
                ->references('id')->on('results')->nullOnDelete();
            $table->foreign('copied_from_id', 'results_copied_from_id_foreign')
                ->references('id')->on('results')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropForeign('results_proposal_id_foreign');
            $table->dropForeign('results_contributes_to_result_id_foreign');
            $table->dropForeign('results_copied_from_id_foreign');
            $table->dropColumn(['proposal_id', 'contributes_to_result_id', 'copied_from_id']);
        });
    }
};
