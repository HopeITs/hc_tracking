<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests to reopen a validated monthly report (contracts §13, D-09a).
     * Requests are never deleted, so the report they point at restricts.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_reopen_requests')) {
            throw new RuntimeException('monthly_report_reopen_requests already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_reopen_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_id');
            $table->unsignedBigInteger('requested_by');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->foreign('monthly_report_id', 'monthly_report_reopen_requests_report_foreign')
                ->references('id')->on('monthly_reports')->restrictOnDelete();
            $table->foreign('requested_by', 'monthly_report_reopen_requests_requested_by_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('decided_by', 'monthly_report_reopen_requests_decided_by_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['monthly_report_id', 'status'], 'monthly_report_reopen_requests_report_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_reopen_requests');
    }
};
