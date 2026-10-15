<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A milestone may belong to a proposal alone (contracts.md §12.2):
     * project_id becomes nullable, and SaveProposalMilestone checks that at
     * least one of proposal_id / project_id is set. planned_dates_edited_at
     * marks dates a user changed after creation, which the approval rebase
     * leaves alone.
     */
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();

            if (! Schema::hasColumn('milestones', 'planned_dates_edited_at')) {
                $table->dateTime('planned_dates_edited_at')->nullable()->after('actual_end_date');
            }
        });
    }

    /**
     * project_id cannot return to NOT NULL while proposal-only milestones
     * exist: refuse with their ids instead of deleting planning data.
     */
    public function down(): void
    {
        $proposalOnlyIds = DB::table('milestones')->whereNull('project_id')->orderBy('id')->pluck('id')->all();

        if ($proposalOnlyIds !== []) {
            throw new RuntimeException('Cannot make milestones.project_id NOT NULL: proposal-only milestones exist (ids '.implode(', ', $proposalOnlyIds).'). Give them a project or delete them first.');
        }

        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();

            if (Schema::hasColumn('milestones', 'planned_dates_edited_at')) {
                $table->dropColumn('planned_dates_edited_at');
            }
        });
    }
};
