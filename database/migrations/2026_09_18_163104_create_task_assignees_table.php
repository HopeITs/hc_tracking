<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task can now have several assignees. `employee_id` holds an HR
     * employee id with no foreign key, the same "cross-database, no FK"
     * convention already used by `tasks.assigned_to` (see .ai/rules/models.md)
     * — the `hr` connection can't be joined to from here.
     */
    public function up(): void
    {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->timestamps();

            $table->unique(['task_id', 'employee_id']);
        });

        // Every existing task already has exactly one assignee in the column
        // being retired — carry it over as that task's first assignee.
        DB::table('tasks')
            ->select('id', 'assigned_to')
            ->orderBy('id')
            ->chunkById(500, function ($tasks) {
                $now = now();

                DB::table('task_assignees')->insert(
                    $tasks->map(fn ($task) => [
                        'task_id' => $task->id,
                        'employee_id' => $task->assigned_to,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_to')->nullable()->after('milestone_id');
        });

        // Restore each task's first (lowest id) assignee — extra assignees
        // beyond the first are lost, since the old column only ever held one.
        DB::table('task_assignees')
            ->select('task_id', 'employee_id')
            ->orderBy('task_id')
            ->orderBy('id')
            ->get()
            ->unique('task_id')
            ->each(function ($assignee) {
                DB::table('tasks')->where('id', $assignee->task_id)->update(['assigned_to' => $assignee->employee_id]);
            });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_to')->nullable(false)->change();
        });

        Schema::dropIfExists('task_assignees');
    }
};
