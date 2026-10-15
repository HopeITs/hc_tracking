<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The month/week calendar of a framework activity (contracts §11.2,
     * D-49). One row per scheduled month; `project_id` NULL is the proposal
     * calendar, a project id a per-center override; `weeks` NULL is the whole
     * month.
     *
     * The unique index does not stop two proposal-calendar rows for the same
     * month (MySQL treats NULLs as distinct): SaveActivitySchedule writes
     * under a lock on the activity row instead.
     */
    public function up(): void
    {
        if (Schema::hasTable('activity_schedules')) {
            throw new RuntimeException('activity_schedules already exists; this migration has partly run.');
        }

        Schema::create('activity_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('result_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('month');
            $table->json('weeks')->nullable();
            $table->timestamps();

            $table->foreign('result_id', 'activity_schedules_result_id_foreign')
                ->references('id')->on('results')->cascadeOnDelete();
            $table->foreign('project_id', 'activity_schedules_project_id_foreign')
                ->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['result_id', 'project_id', 'month'], 'activity_schedules_result_project_month_unique');
            $table->index('project_id', 'activity_schedules_project_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_schedules');
    }
};
