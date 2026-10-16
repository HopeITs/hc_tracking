<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department tasks on a proposal's plan (contracts.md §12.2): the HR
     * department a task is handed to, its kind, and the media-deliverable
     * delivery trail. planned_dates_edited_at marks a row whose dates a user
     * changed after creation, so the approval rebase leaves it alone.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'department_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('source_id');
            $table->string('kind', 30)->default('general')->after('department_id');
            $table->json('media_kinds')->nullable()->after('kind');
            $table->string('delivery_state', 20)->nullable()->after('media_kinds');
            $table->date('requested_on')->nullable()->after('delivery_state');
            $table->date('delivered_on')->nullable()->after('requested_on');
            $table->string('delivered_link', 2048)->nullable()->after('delivered_on');
            $table->text('delivered_text')->nullable()->after('delivered_link');
            $table->dateTime('accepted_at')->nullable()->after('delivered_text');
            $table->text('returned_reason')->nullable()->after('accepted_at');
            $table->dateTime('planned_dates_edited_at')->nullable()->after('returned_reason');

            $table->index('department_id', 'tasks_department_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tasks', 'department_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_department_id_index');
            $table->dropColumn([
                'department_id',
                'kind',
                'media_kinds',
                'delivery_state',
                'requested_on',
                'delivered_on',
                'delivered_link',
                'delivered_text',
                'accepted_at',
                'returned_reason',
                'planned_dates_edited_at',
            ]);
        });
    }
};
