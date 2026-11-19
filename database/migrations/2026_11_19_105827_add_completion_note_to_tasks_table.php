<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a task was marked done while checks were still open (the proposal
     * framework task's "done anyway" reason), and who marked it done.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'completion_note')) {
            throw new RuntimeException('tasks.completion_note already exists; nothing was changed.');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->text('completion_note')->nullable()->after('completed_date');
            $table->foreignId('completed_by')->nullable()->after('completion_note')->constrained('users', indexName: 'tasks_completed_by_foreign')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign('tasks_completed_by_foreign');
            $table->dropColumn(['completion_note', 'completed_by']);
        });
    }
};
