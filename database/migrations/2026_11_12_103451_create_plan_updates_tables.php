<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The monthly update of an annual plan (2026-11-11 agreement), one per
     * centre (project) and month, separate from the monthly report module.
     * Part 1 tracks the activities (a status per batch and week, the numbers
     * done, supporting files); part 2 is the monthly report (five narrative
     * sections and one beneficiaries row). The review chain is officer →
     * MEAL manager → program manager; a return keeps everything entered.
     */
    public function up(): void
    {
        Schema::create('plan_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_plan_id');
            $table->unsignedBigInteger('project_id');
            $table->date('month');
            $table->string('status', 20)->default('draft');
            $table->text('activities_implemented')->nullable();
            $table->text('challenges')->nullable();
            $table->text('next_month')->nullable();
            $table->text('accountability')->nullable();
            $table->unsignedInteger('males')->nullable();
            $table->unsignedInteger('females')->nullable();
            $table->unsignedInteger('with_disability')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->boolean('resent')->default(false);
            $table->dateTime('officer_approved_at')->nullable();
            $table->unsignedBigInteger('officer_approved_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('seen_at')->nullable();
            $table->unsignedBigInteger('seen_by')->nullable();
            $table->text('program_manager_note')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->string('returned_to', 20)->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->unique(['activity_plan_id', 'project_id', 'month'], 'plan_updates_plan_project_month_unique');
            $table->index('status', 'plan_updates_status_index');

            $table->foreign('activity_plan_id', 'plan_updates_activity_plan_id_foreign')
                ->references('id')->on('activity_plans')->cascadeOnDelete();
            $table->foreign('project_id', 'plan_updates_project_id_foreign')
                ->references('id')->on('projects')->cascadeOnDelete();

            foreach (['sent_by', 'officer_approved_by', 'reviewed_by', 'seen_by', 'returned_by'] as $column) {
                $table->foreign($column, "plan_updates_{$column}_foreign")->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::create('plan_update_weeks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_update_id');
            $table->unsignedBigInteger('activity_plan_batch_id');
            $table->date('week');
            $table->string('status', 20);
            $table->string('reason', 500)->nullable();
            $table->date('new_week')->nullable();
            $table->timestamps();

            $table->unique(['plan_update_id', 'activity_plan_batch_id', 'week'], 'plan_update_weeks_update_batch_week_unique');

            $table->foreign('plan_update_id', 'plan_update_weeks_plan_update_id_foreign')
                ->references('id')->on('plan_updates')->cascadeOnDelete();
            $table->foreign('activity_plan_batch_id', 'plan_update_weeks_batch_id_foreign')
                ->references('id')->on('activity_plan_batches')->cascadeOnDelete();
        });

        Schema::create('plan_update_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_update_id');
            $table->unsignedBigInteger('activity_plan_batch_id');
            $table->unsignedInteger('done_count');
            $table->timestamps();

            $table->unique(['plan_update_id', 'activity_plan_batch_id'], 'plan_update_numbers_update_batch_unique');

            $table->foreign('plan_update_id', 'plan_update_numbers_plan_update_id_foreign')
                ->references('id')->on('plan_updates')->cascadeOnDelete();
            $table->foreign('activity_plan_batch_id', 'plan_update_numbers_batch_id_foreign')
                ->references('id')->on('activity_plan_batches')->cascadeOnDelete();
        });

        Schema::create('plan_update_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_update_id');
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('plan_update_id', 'plan_update_files_plan_update_id_foreign')
                ->references('id')->on('plan_updates')->cascadeOnDelete();
            $table->foreign('uploaded_by', 'plan_update_files_uploaded_by_foreign')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('plan_update_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_update_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('plan_update_id', 'plan_update_questions_plan_update_id_foreign')
                ->references('id')->on('plan_updates')->cascadeOnDelete();
            $table->foreign('user_id', 'plan_update_questions_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_update_questions');
        Schema::dropIfExists('plan_update_files');
        Schema::dropIfExists('plan_update_numbers');
        Schema::dropIfExists('plan_update_weeks');
        Schema::dropIfExists('plan_updates');
    }
};
