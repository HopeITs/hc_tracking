<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The annual activity plan agreed with MEAL (2026-11-11): one plan per
     * proposal and program, made by the program manager and approved by the
     * MEAL manager. Each planned logframe activity says how it runs, what it
     * counts, its governorates, and its batches of Monday weeks. The batches
     * are the only stored dates; activity_schedules is written from them on
     * approval (SyncActivitySchedules) so the monthly report keeps reading
     * its month/week calendar.
     */
    public function up(): void
    {
        Schema::create('activity_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedBigInteger('program_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('step')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('sent_back_at')->nullable();
            $table->unsignedBigInteger('sent_back_by')->nullable();
            $table->text('sent_back_reason')->nullable();
            $table->timestamps();

            $table->unique('slug', 'activity_plans_slug_unique');
            $table->unique(['proposal_id', 'program_id'], 'activity_plans_proposal_program_unique');
            $table->index('status', 'activity_plans_status_index');

            $table->foreign('proposal_id', 'activity_plans_proposal_id_foreign')
                ->references('id')->on('proposals')->cascadeOnDelete();
            $table->foreign('program_id', 'activity_plans_program_id_foreign')
                ->references('id')->on('programs')->cascadeOnDelete();

            foreach (['created_by', 'submitted_by', 'approved_by', 'sent_back_by'] as $column) {
                $table->foreign($column, "activity_plans_{$column}_foreign")
                    ->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::create('activity_plan_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_plan_id');
            $table->unsignedBigInteger('result_id');
            $table->boolean('is_single_week')->default(false);
            $table->string('unit', 20)->default('session');
            $table->string('unit_other', 60)->nullable();
            $table->unsignedInteger('expected_people')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_ready')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['activity_plan_id', 'result_id'], 'activity_plan_items_plan_result_unique');

            $table->foreign('activity_plan_id', 'activity_plan_items_activity_plan_id_foreign')
                ->references('id')->on('activity_plans')->cascadeOnDelete();
            $table->foreign('result_id', 'activity_plan_items_result_id_foreign')
                ->references('id')->on('results')->cascadeOnDelete();
        });

        // city_id is an HR city id: no foreign key, like every HR link.
        Schema::create('activity_plan_item_cities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_plan_item_id');
            $table->unsignedBigInteger('city_id');
            $table->timestamps();

            $table->unique(['activity_plan_item_id', 'city_id'], 'activity_plan_item_cities_item_city_unique');

            $table->foreign('activity_plan_item_id', 'activity_plan_item_cities_item_id_foreign')
                ->references('id')->on('activity_plan_items')->cascadeOnDelete();
        });

        // start_week / end_week are Mondays; a single-week item has start = end.
        Schema::create('activity_plan_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_plan_item_id');
            $table->date('start_week');
            $table->date('end_week');
            $table->unsignedInteger('planned_count')->nullable();
            $table->decimal('hours_each', 5, 2)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['activity_plan_item_id', 'sort'], 'activity_plan_batches_item_sort_index');

            $table->foreign('activity_plan_item_id', 'activity_plan_batches_item_id_foreign')
                ->references('id')->on('activity_plan_items')->cascadeOnDelete();
        });

        Schema::create('activity_plan_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_plan_id');
            $table->unsignedBigInteger('activity_plan_item_id');
            $table->string('trigger', 20);
            $table->string('description', 255);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('activity_plan_id', 'activity_plan_follow_ups_activity_plan_id_foreign')
                ->references('id')->on('activity_plans')->cascadeOnDelete();
            $table->foreign('activity_plan_item_id', 'activity_plan_follow_ups_item_id_foreign')
                ->references('id')->on('activity_plan_items')->cascadeOnDelete();
            $table->foreign('created_by', 'activity_plan_follow_ups_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_plan_follow_ups');
        Schema::dropIfExists('activity_plan_batches');
        Schema::dropIfExists('activity_plan_item_cities');
        Schema::dropIfExists('activity_plan_items');
        Schema::dropIfExists('activity_plans');
    }
};
