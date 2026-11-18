<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Milestones are removed from the whole app (user decision 2026-11-17):
     * they come back later as checkpoints that live only inside an approved
     * annual plan, in a table of their own.
     *
     * Every polymorphic row that pointed at a milestone is settled first,
     * because no foreign key can do it: a task moves to the milestone's
     * project (or, for a proposal-wide milestone, its proposal) so no work is
     * lost; the milestone's files, Gantt links, baseline rows, activity and
     * notifications go. Then the table and its four permissions go.
     */
    public function up(): void
    {
        $legacyTasks = DB::table('tasks')->whereIn('source_type', ['milestone', 'App\\Models\\Milestone']);
        $hasMilestones = Schema::hasTable('milestones');

        if ($hasMilestones) {
            $unmappedTaskIds = (clone $legacyTasks)
                ->leftJoin('milestones', 'milestones.id', '=', 'tasks.source_id')
                ->whereNull('milestones.project_id')
                ->whereNull('milestones.proposal_id')
                ->orderBy('tasks.id')
                ->limit(10)
                ->pluck('tasks.id');
        } else {
            $unmappedTaskIds = (clone $legacyTasks)->orderBy('id')->limit(10)->pluck('id');
        }

        if ($unmappedTaskIds->isNotEmpty()) {
            throw new RuntimeException('Move these tasks to a project or proposal before removing milestones. Task IDs: '.$unmappedTaskIds->implode(', '));
        }

        if ($hasMilestones) {
            DB::table('milestones')
                ->select(['id', 'project_id', 'proposal_id'])
                ->where(fn ($query) => $query->whereNotNull('project_id')->orWhereNotNull('proposal_id'))
                ->chunkById(500, function ($milestones) use ($legacyTasks): void {
                    foreach ($milestones as $milestone) {
                        $source = $milestone->project_id !== null
                            ? ['source_type' => 'project', 'source_id' => $milestone->project_id]
                            : ['source_type' => 'proposal', 'source_id' => $milestone->proposal_id];

                        (clone $legacyTasks)
                            ->where('source_id', $milestone->id)
                            ->update($source);
                    }
                });
        }

        DB::table('media')->where('mediable_type', 'milestone')->delete();
        DB::table('schedule_dependencies')->where('predecessor_type', 'milestone')->orWhere('successor_type', 'milestone')->delete();
        DB::table('schedule_baseline_items')->where('item_type', 'milestone')->delete();
        DB::table('activities')->where('subject_type', 'milestone')->delete();
        DB::table('notifications')->where('subject_type', 'milestone')->delete();
        DB::table('notification_alert_ledger')->where('subject_type', 'milestone')->delete();
        DB::table('assignments')->where('assignable_type', 'milestone')->delete();

        Schema::dropIfExists('milestones');

        Permission::query()->where('name', 'like', 'milestones.%')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Recreates the empty table as it last stood. The deleted rows, files,
     * links and permissions do not come back.
     */
    public function down(): void
    {
        Schema::create('milestones', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable()->unique();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('planned_start_date');
            $table->date('planned_end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->dateTime('planned_dates_edited_at')->nullable();
            $table->string('status', 50)->default('planned')->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('weight_percentage', 5, 2)->default(0);
            $table->unsignedBigInteger('responsible_user_id')->index('milestones_responsible_user_id_foreign');
            $table->foreignId('parent_milestone_id')->nullable()->constrained('milestones')->cascadeOnDelete();
            $table->timestamps();
            $table->foreignId('proposal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('result_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_final_report')->default(false);
            $table->foreignId('copied_from_id')->nullable()->constrained('milestones')->nullOnDelete();
        });
    }
};
