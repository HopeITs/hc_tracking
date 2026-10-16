<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Typed evidence of a monthly report (contracts §13, §16, D-42), on the
     * report, one of its lines or one of its activity entries. The stored-file
     * columns match `report_files` and are written through StoredFiles.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_files')) {
            throw new RuntimeException('monthly_report_files already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_id');
            $table->unsignedBigInteger('monthly_report_line_id')->nullable();
            $table->unsignedBigInteger('monthly_report_activity_entry_id')->nullable();
            $table->unsignedBigInteger('evidence_type_id');
            $table->text('path');
            $table->string('disk', 50)->default('local');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('encryption_key_id', 20)->nullable();
            $table->string('type', 50);
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->foreign('monthly_report_id', 'monthly_report_files_report_foreign')
                ->references('id')->on('monthly_reports')->cascadeOnDelete();
            $table->foreign('monthly_report_line_id', 'monthly_report_files_line_foreign')
                ->references('id')->on('monthly_report_lines')->cascadeOnDelete();
            $table->foreign('monthly_report_activity_entry_id', 'monthly_report_files_entry_foreign')
                ->references('id')->on('monthly_report_activity_entries')->cascadeOnDelete();
            $table->foreign('evidence_type_id', 'monthly_report_files_evidence_type_foreign')
                ->references('id')->on('evidence_types')->restrictOnDelete();
            $table->foreign('uploaded_by', 'monthly_report_files_uploaded_by_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_files');
    }
};
