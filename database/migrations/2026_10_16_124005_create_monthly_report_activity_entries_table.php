<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The status of each logframe activity reported in a month (contracts
     * §13, D-51). `status` stays NULL until the manager picks one.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_activity_entries')) {
            throw new RuntimeException('monthly_report_activity_entries already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_activity_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_id');
            $table->unsignedBigInteger('result_id');
            $table->string('status', 20)->nullable();
            $table->text('reason')->nullable();
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->date('executed_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['monthly_report_id', 'result_id'], 'monthly_report_activity_entries_report_result_unique');
            $table->foreign('monthly_report_id', 'monthly_report_activity_entries_report_foreign')
                ->references('id')->on('monthly_reports')->cascadeOnDelete();
            $table->foreign('result_id', 'monthly_report_activity_entries_result_foreign')
                ->references('id')->on('results')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_activity_entries');
    }
};
