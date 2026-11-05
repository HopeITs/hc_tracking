<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A report task points at the report it tracks (W-13, D-64): a donor
     * report now, a monthly report later. No foreign key: the morph map key
     * decides the table.
     */
    public function up(): void
    {
        foreach (['reportable_type', 'reportable_id'] as $column) {
            if (Schema::hasColumn('tasks', $column)) {
                throw new RuntimeException("tasks.{$column} already exists; nothing was changed.");
            }
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('reportable_type', 255)->nullable()->after('copied_from_id');
            $table->unsignedBigInteger('reportable_id')->nullable()->after('reportable_type');
            $table->index(['reportable_type', 'reportable_id'], 'tasks_reportable_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_reportable_index');
            $table->dropColumn(['reportable_type', 'reportable_id']);
        });
    }
};
