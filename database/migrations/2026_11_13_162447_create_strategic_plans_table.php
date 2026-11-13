<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A program's strategic plan inside one strategy (MEAL manager request,
     * 2026-11-13): written by the MEAL manager, approved by the program's
     * manager. It is not a program or project plan: it says, year by year of
     * the strategy, what must be achieved and which program indicators
     * measure it (strategic_plan_years, strategic_plan_targets).
     */
    public function up(): void
    {
        Schema::create('strategic_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategy_id');
            $table->unsignedBigInteger('program_id');
            $table->string('status', 20)->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('sent_back_at')->nullable();
            $table->unsignedBigInteger('sent_back_by')->nullable();
            $table->text('sent_back_reason')->nullable();
            $table->timestamps();

            $table->unique(['strategy_id', 'program_id'], 'strategic_plans_strategy_program_unique');

            $table->foreign('strategy_id', 'strategic_plans_strategy_id_foreign')
                ->references('id')->on('strategies')->cascadeOnDelete();
            $table->foreign('program_id', 'strategic_plans_program_id_foreign')
                ->references('id')->on('programs')->cascadeOnDelete();

            foreach (['submitted_by', 'approved_by', 'sent_back_by'] as $column) {
                $table->foreign($column, "strategic_plans_{$column}_foreign")
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_plans');
    }
};
