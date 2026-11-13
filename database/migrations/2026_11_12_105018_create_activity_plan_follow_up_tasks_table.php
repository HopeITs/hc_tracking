<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which follow-up task fired for which centre, and the task it became:
     * a MEAL follow-up fires once per centre (FireActivityPlanFollowUps).
     */
    public function up(): void
    {
        Schema::create('activity_plan_follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_plan_follow_up_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id');
            $table->timestamps();

            $table->unique(['activity_plan_follow_up_id', 'project_id'], 'activity_plan_follow_up_tasks_follow_up_project_unique');

            $table->foreign('activity_plan_follow_up_id', 'apfut_follow_up_id_foreign')->references('id')->on('activity_plan_follow_ups')->cascadeOnDelete();
            $table->foreign('project_id', 'apfut_project_id_foreign')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('task_id', 'apfut_task_id_foreign')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_plan_follow_up_tasks');
    }
};
