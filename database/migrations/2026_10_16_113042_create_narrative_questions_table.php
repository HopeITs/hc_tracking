<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The org-wide narrative question bank (contracts.md §12.4, D-43), managed by
 * MEAL (`narrative-questions.manage`). Each new report template copies the
 * active questions (T33 slice 2), so the bank never links to a project.
 * `sort_order` counts within a frequency.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('narrative_questions')) {
            return;
        }

        Schema::create('narrative_questions', function (Blueprint $table): void {
            $table->id();
            $table->string('text_ar', 1000);
            $table->string('text_en', 1000)->nullable();
            $table->string('frequency', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'frequency', 'sort_order'], 'narrative_questions_active_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('narrative_questions');
    }
};
