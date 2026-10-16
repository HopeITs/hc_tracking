<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One monthly report template per project (contracts.md §12.4, T33). It is
 * pre-filled as `pending` on donor approval and confirmed by MEAL, which
 * activates an approved project through ActivateProject.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_templates')) {
            Schema::create('report_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects', indexName: 'report_templates_project_id_foreign')->cascadeOnDelete();
                $table->string('status', 20)->default('pending');
                $table->dateTime('confirmed_at')->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users', indexName: 'report_templates_confirmed_by_foreign')->nullOnDelete();
                $table->timestamps();

                $table->unique('project_id', 'report_templates_project_id_unique');
                $table->index('status', 'report_templates_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
