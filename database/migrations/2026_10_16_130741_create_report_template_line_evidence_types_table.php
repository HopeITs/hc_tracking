<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence types a report template line requires (contracts.md §12.4).
 * The table name is the frozen contract's, not Laravel's pivot inference, so
 * ReportTemplateLine::evidenceTypes() names it. A used evidence type
 * restricts on delete: it is deactivated instead (EvidenceType::isInUse()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_template_line_evidence_types')) {
            Schema::create('report_template_line_evidence_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_template_line_id')->constrained('report_template_lines', indexName: 'rtl_evidence_types_line_id_foreign')->cascadeOnDelete();
                $table->foreignId('evidence_type_id')->constrained('evidence_types', indexName: 'rtl_evidence_types_evidence_type_id_foreign')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['report_template_line_id', 'evidence_type_id'], 'rtl_evidence_types_line_type_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template_line_evidence_types');
    }
};
