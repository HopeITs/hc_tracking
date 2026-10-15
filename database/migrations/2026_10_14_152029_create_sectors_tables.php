<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sectors describe what a project covers (contracts.md §12.5, D-47). They are
 * linked to projects only, through the `project_sector` pivot, never through
 * a column on `projects`. A sector some project uses is deactivated, never
 * deleted: `sector_id` restricts on delete as the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sectors')) {
            Schema::create('sectors', function (Blueprint $table): void {
                $table->id();
                $table->string('name_ar');
                $table->string('name_en')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order'], 'sectors_active_order_index');
            });
        }

        if (! Schema::hasTable('project_sector')) {
            Schema::create('project_sector', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects', indexName: 'project_sector_project_id_foreign')->cascadeOnDelete();
                $table->foreignId('sector_id')->constrained('sectors', indexName: 'project_sector_sector_id_foreign')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['project_id', 'sector_id'], 'project_sector_project_id_sector_id_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_sector');
        Schema::dropIfExists('sectors');
    }
};
