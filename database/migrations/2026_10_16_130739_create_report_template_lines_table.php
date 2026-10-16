<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line per target a project reports monthly (contracts.md §12.4). A line
 * is never deleted once confirmed: it is retired by setting `effective_to`,
 * and its target restricts on delete so the history keeps its indicator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_template_lines')) {
            Schema::create('report_template_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_template_id')->constrained('report_templates', indexName: 'rt_lines_report_template_id_foreign')->cascadeOnDelete();
                $table->foreignId('plan_indicator_id')->constrained('plan_indicators', indexName: 'rt_lines_plan_indicator_id_foreign')->restrictOnDelete();
                $table->string('status', 20)->default('pending');
                $table->string('schedule', 30)->default('library_default');
                $table->json('schedule_months')->nullable();
                $table->unsignedSmallInteger('once_after_months')->nullable();
                $table->string('entered_by', 20);
                $table->unsignedInteger('fixed_denominator')->nullable();
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users', indexName: 'rt_lines_confirmed_by_foreign')->nullOnDelete();
                $table->timestamps();

                $table->unique(['report_template_id', 'plan_indicator_id'], 'rt_lines_template_target_unique');
                $table->index('status', 'rt_lines_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template_lines');
    }
};
