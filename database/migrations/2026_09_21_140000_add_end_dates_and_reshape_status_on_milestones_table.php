<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A milestone now spans a start and an end on both the planned and the
     * actual side: planned_date becomes planned_start_date (paired with the
     * new planned_end_date), and actual_date becomes actual_start_date
     * (paired with the new actual_end_date).
     *
     * `status` gains 'ongoing' and 'postponed'. 'pending' is renamed to
     * 'planned', and 'missed' is dropped — both "missed" and "overdue" are
     * now derived from the dates rather than stored (see
     * Milestone::isMissed() / isOverdue()), so any existing 'missed' row
     * falls back to 'planned', matching the reasoning already used when
     * TaskStatus::Blocked was removed.
     */
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->renameColumn('planned_date', 'planned_start_date');
            $table->renameColumn('actual_date', 'actual_start_date');
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->date('planned_end_date')->nullable()->after('planned_start_date');
            $table->date('actual_end_date')->nullable()->after('actual_start_date');
        });

        DB::table('milestones')->where('status', 'pending')->update(['status' => 'planned']);
        DB::table('milestones')->where('status', 'missed')->update(['status' => 'planned']);

        // doctrine/dbal isn't installed, so the column default is changed with
        // raw SQL rather than Blueprint::change().
        DB::statement("ALTER TABLE milestones ALTER COLUMN status SET DEFAULT 'planned'");
    }

    /**
     * The pending/missed split can't be recovered — every renamed-back row
     * becomes 'pending'.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE milestones ALTER COLUMN status SET DEFAULT 'pending'");

        DB::table('milestones')->where('status', 'planned')->update(['status' => 'pending']);

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['planned_end_date', 'actual_end_date']);
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->renameColumn('planned_start_date', 'planned_date');
            $table->renameColumn('actual_start_date', 'actual_date');
        });
    }
};
