<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The department work a proposal stage needs, written once by the PR Manager
 * instead of added by hand every time (D-63, W-12). A listener on
 * ProposalStatusChanged creates a task — or the final-report milestone — from
 * every active template of the status the proposal just entered.
 *
 * `department_id` is an HR id, so it carries no foreign key and no name is
 * copied here. `tasks.stage_template_id` and `milestones.stage_template_id`
 * are what make creation idempotent: one row per (proposal, template).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('proposal_stage_task_templates')) {
            Schema::create('proposal_stage_task_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('status', 30);
                $table->unsignedBigInteger('department_id');
                $table->string('kind', 30);
                // 30, not the 20 the [contract] text says: `final_report_milestone`
                // is 22 characters, so the width it names cannot hold the value
                // it names. Flagged for Dev 1 in the handoff.
                $table->string('creates', 30)->default('task');
                $table->string('title_en');
                $table->string('title_ar');
                $table->text('description_en')->nullable();
                $table->text('description_ar')->nullable();
                $table->smallInteger('due_offset_days')->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('repeat_on_reentry')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['status', 'department_id', 'kind', 'title_en'], 'stage_task_templates_unique');
                $table->index(['status', 'is_active'], 'stage_task_templates_status_index');
            });
        }

        foreach (['tasks', 'milestones'] as $owner) {
            if (Schema::hasColumn($owner, 'stage_template_id')) {
                continue;
            }

            Schema::table($owner, function (Blueprint $table) use ($owner): void {
                $table->foreignId('stage_template_id')
                    ->nullable()
                    ->constrained('proposal_stage_task_templates')
                    ->nullOnDelete();

                $table->index('stage_template_id', $owner.'_stage_template_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['tasks', 'milestones'] as $owner) {
            if (! Schema::hasColumn($owner, 'stage_template_id')) {
                continue;
            }

            Schema::table($owner, function (Blueprint $table) use ($owner): void {
                $table->dropForeign(['stage_template_id']);
                $table->dropIndex($owner.'_stage_template_id_index');
                $table->dropColumn('stage_template_id');
            });
        }

        Schema::dropIfExists('proposal_stage_task_templates');
    }
};
