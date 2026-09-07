<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_indicator_id')->constrained('plan_indicators')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('planned_date');
            $table->decimal('value', 12, 2);
            $table->text('narrative_report_notes')->nullable();
            $table->date('submit_date')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->date('approved_date')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->date('edited_date')->nullable();
            $table->boolean('is_seen_by_meal')->default(false);
            $table->timestamps();

            $table->index(['plan_indicator_id', 'planned_date']);
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
