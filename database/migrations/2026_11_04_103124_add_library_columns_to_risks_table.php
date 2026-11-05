<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A risk now lives on a result of a proposal's logframe and is picked from
 * the library (contracts.md §7, W-09, D-61). Existing rows keep their
 * project and stay as they are; `project_id` becomes nullable for the new
 * rows. MySQL does not roll DDL back, so every pre-check runs first.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['name_en', 'library_risk_id', 'result_id', 'proposal_id'] as $column) {
            if (Schema::hasColumn('risks', $column)) {
                throw new RuntimeException("risks.{$column} already exists; nothing was changed.");
            }
        }

        Schema::table('risks', function (Blueprint $table): void {
            $table->string('name_en')->nullable()->after('name');
            $table->foreignId('library_risk_id')->nullable()->after('project_id')
                ->constrained('risk_library', indexName: 'risks_library_risk_id_foreign')->restrictOnDelete();
            $table->foreignId('result_id')->nullable()->after('library_risk_id')
                ->constrained('results', indexName: 'risks_result_id_foreign')->cascadeOnDelete();
            $table->foreignId('proposal_id')->nullable()->after('result_id')
                ->constrained('proposals', indexName: 'risks_proposal_id_foreign')->cascadeOnDelete();
            $table->index(['proposal_id', 'result_id'], 'risks_proposal_id_result_id_index');
        });

        Schema::table('risks', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });
    }

    /**
     * Refuses while proposal-level risks exist: they have no project to go back to.
     */
    public function down(): void
    {
        if (DB::table('risks')->whereNull('project_id')->exists()) {
            throw new RuntimeException('Risks without a project exist; remove them before rolling back.');
        }

        Schema::table('risks', function (Blueprint $table): void {
            $table->dropForeign('risks_library_risk_id_foreign');
            $table->dropForeign('risks_result_id_foreign');
            $table->dropForeign('risks_proposal_id_foreign');
            $table->dropIndex('risks_proposal_id_result_id_index');
            $table->dropColumn(['name_en', 'library_risk_id', 'result_id', 'proposal_id']);
        });

        Schema::table('risks', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }
};
