<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time-series progress readings against an indicator.
     *
     * This is NOT a second `reports` table. A report is a narrative document
     * for a reporting period, carrying an executive summary, challenges and
     * lessons, and it measures one plan_indicators target. A progress entry is
     * a single dated number against the indicator itself, with the evidence it
     * came from. Logging a monthly figure must not require writing a quarterly
     * narrative, and the trend chart must not have to filter narrative rows
     * out of its series.
     *
     * `indicator_id` cascades: readings have no meaning without the indicator
     * they measure, and deleting an indicator is already refused by the UI
     * while any target exists.
     *
     * `recorded_by` RESTRICTs, matching reports.user_id and task_updates.user_id
     * — who recorded a figure is part of the figure.
     *
     * The (indicator_id, date) index carries the trend query, which always
     * reads one indicator's series in date order.
     */
    public function up(): void
    {
        Schema::create('indicator_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('value', 12, 2);
            $table->text('notes')->nullable();
            $table->string('source_of_verification')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['indicator_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicator_progress');
    }
};
