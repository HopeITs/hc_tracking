<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cover the hot filters that had no index: task visibility by assignee,
     * the deadline/stalled alert scans by status and due date, the Gantt
     * phase filter on proposals and the milestone status boards. Additive
     * only — no data or query result changes, just fewer rows read as the
     * tables grow (and less room for a slow query to be used as a DoS lever).
     */
    public function up(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->index('employee_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['status', 'due_date']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
