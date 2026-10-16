<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The last confirmed status of each activity on a project (contracts
     * §13, D-51). Written only when MEAL validates a monthly report;
     * restored from the activity log when a reopen is granted.
     * `monthly_report_id` is the report that last confirmed it.
     */
    public function up(): void
    {
        if (Schema::hasTable('project_activity_statuses')) {
            throw new RuntimeException('project_activity_statuses already exists; this migration has partly run.');
        }

        Schema::create('project_activity_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('result_id');
            $table->string('status', 20);
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->unsignedBigInteger('monthly_report_id');
            $table->timestamps();

            $table->unique(['project_id', 'result_id'], 'project_activity_statuses_project_result_unique');
            $table->foreign('project_id', 'project_activity_statuses_project_foreign')
                ->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('result_id', 'project_activity_statuses_result_foreign')
                ->references('id')->on('results')->cascadeOnDelete();
            $table->foreign('monthly_report_id', 'project_activity_statuses_report_foreign')
                ->references('id')->on('monthly_reports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activity_statuses');
    }
};
