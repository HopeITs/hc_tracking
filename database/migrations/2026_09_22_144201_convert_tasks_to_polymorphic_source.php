<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `milestone_id` (nullable) and `proposal_id` (required) are replaced by a
     * single polymorphic `source_type`/`source_id` pair, matching
     * `plan_indicators`. Every existing task has one or the other set, so the
     * backfill is unambiguous: a milestone wins when present, otherwise the
     * task's source is its proposal.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('source_type', 50)->nullable()->after('parent_task_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
        });

        DB::table('tasks')->whereNotNull('milestone_id')->update([
            'source_type' => 'milestone',
            'source_id' => DB::raw('milestone_id'),
        ]);

        DB::table('tasks')->whereNull('milestone_id')->update([
            'source_type' => 'proposal',
            'source_id' => DB::raw('proposal_id'),
        ]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('source_type', 50)->nullable(false)->change();
            $table->unsignedBigInteger('source_id')->nullable(false)->change();
            $table->index(['source_type', 'source_id']);

            $table->dropForeign(['milestone_id']);
            $table->dropForeign(['proposal_id']);
            $table->dropColumn(['milestone_id', 'proposal_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only milestone/proposal sources can round-trip; a task sourced from a
     * project, program or department has no column to fall back to and is
     * left with a null milestone_id/proposal_id.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('milestone_id')->nullable()->after('parent_task_id')->constrained('milestones')->nullOnDelete();
            $table->foreignId('proposal_id')->nullable()->after('milestone_id')->constrained('proposals')->cascadeOnDelete();
        });

        DB::table('tasks')->where('source_type', 'milestone')->update([
            'milestone_id' => DB::raw('source_id'),
        ]);

        DB::table('tasks')->where('source_type', 'proposal')->update([
            'proposal_id' => DB::raw('source_id'),
        ]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('proposal_id')->nullable(false)->change();
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
