<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's own copy of the narrative question bank (contracts.md §12.4),
 * taken when its template is created and edited by MEAL per project. The
 * bank question it came from may be deleted later; the copy stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_template_questions')) {
            Schema::create('report_template_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_template_id')->constrained('report_templates', indexName: 'rt_questions_report_template_id_foreign')->cascadeOnDelete();
                $table->foreignId('narrative_question_id')->nullable()->constrained('narrative_questions', indexName: 'rt_questions_narrative_question_id_foreign')->nullOnDelete();
                $table->string('text_ar', 1000);
                $table->string('text_en', 1000)->nullable();
                $table->string('frequency', 20);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['report_template_id', 'is_active', 'frequency', 'sort_order'], 'rt_questions_template_order_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template_questions');
    }
};
