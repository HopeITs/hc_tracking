<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A narrative report for a period, carrying the actual value reached
     * against one planned target.
     *
     * `status` (draft -> reviewed -> approved) is the single review workflow.
     * There is deliberately NO `is_approved`/`approved_date` or
     * `is_editable`/`edited_date` pair: each boolean could drift from its date,
     * and `is_approved` would have been a second source of the truth `status`
     * holds. Editability is derived — ReportStatus::Draft->isEditable() is
     * true, the other two are false — so it needs no column at all.
     *
     * `is_seen_by_meal` survives as a column because MEAL visibility is a
     * separate review track rather than a stage of this one, and it carries no
     * paired date to drift from.
     *
     * The five narrative columns are what a donor report is actually made of.
     * `narrative_report_notes` is kept alongside them as the free-text catch-all
     * the ERD drew.
     *
     * `planned_date` is the period this figure is reported for;
     * `period_start`/`period_end` bound the window the narrative covers, which
     * is not always the same thing.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_indicator_id')->constrained('plan_indicators')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 50)->default('draft');
            $table->date('planned_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('value', 12, 2);
            $table->text('narrative_report_notes')->nullable();
            $table->text('executive_summary')->nullable();
            $table->text('key_achievements')->nullable();
            $table->text('challenges')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->text('next_period_plans')->nullable();
            $table->date('submit_date')->nullable();
            $table->boolean('is_seen_by_meal')->default(false);
            $table->timestamps();

            $table->index(['plan_indicator_id', 'planned_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
