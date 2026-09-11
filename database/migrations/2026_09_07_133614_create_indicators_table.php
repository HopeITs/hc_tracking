<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The logframe row for a result: what it is measured by, from where, to
     * what, and how often.
     *
     * `target` here is the LIFETIME target and is a different thing from
     * `plan_indicators.target`, which is a per-period commitment made in an
     * annual plan or a proposal. An indicator has one baseline and one
     * end-of-logframe target; the same indicator can carry many period targets.
     * Reports measure against plan_indicators; the trend chart and the RAG
     * rating measure against this column.
     *
     * `project_id` is NULLABLE and `result_id` stays NOT NULL. An indicator
     * always measures a result — that is what makes it a logframe indicator —
     * and may additionally be scoped to one project. Making both nullable would
     * allow an indicator attached to nothing.
     *
     * `frequency` reuses the existing Period enum rather than introducing a
     * fourth vocabulary for the same idea, and defaults to 'quarterly' to match
     * results.checkout_period and plan_indicators.period.
     */
    public function up(): void
    {
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('result_id')->constrained('results')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('measure', 50)->default('numeric');
            $table->string('unit', 50)->nullable();
            $table->decimal('baseline', 12, 2)->nullable();
            $table->decimal('target', 12, 2)->nullable();
            $table->string('frequency', 50)->default('quarterly');
            $table->timestamps();

            $table->unique(['result_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicators');
    }
};
