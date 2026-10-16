<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line per library indicator due in the report's month (contracts
     * §13, §13.1 item 1). The figures are computed by the server from the
     * shares or the breakdown; `not_measured` marks a ratio left unmeasured
     * this month (its value stays NULL, never zero).
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_lines')) {
            throw new RuntimeException('monthly_report_lines already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_id');
            $table->unsignedBigInteger('library_indicator_id');
            $table->string('entered_by', 20);
            $table->decimal('value', 14, 2)->nullable();
            $table->decimal('numerator', 12, 2)->nullable();
            $table->decimal('denominator', 12, 2)->nullable();
            $table->unsignedInteger('fixed_denominator')->nullable();
            $table->boolean('not_measured')->default(false);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('filled_by')->nullable();
            $table->timestamps();

            $table->unique(['monthly_report_id', 'library_indicator_id'], 'monthly_report_lines_report_indicator_unique');
            $table->foreign('monthly_report_id', 'monthly_report_lines_report_foreign')
                ->references('id')->on('monthly_reports')->cascadeOnDelete();
            $table->foreign('library_indicator_id', 'monthly_report_lines_library_indicator_foreign')
                ->references('id')->on('library_indicators')->restrictOnDelete();
            $table->foreign('filled_by', 'monthly_report_lines_filled_by_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_lines');
    }
};
