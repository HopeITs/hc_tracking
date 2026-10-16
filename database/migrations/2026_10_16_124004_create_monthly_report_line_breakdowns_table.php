<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sex and disability breakdown of a line or one of its shares (contracts
     * §13, D-50). `monthly_report_line_share_id` is NULL for a line-level
     * breakdown (`shared` mode). The unique index does not stop two
     * line-level rows of one gender (MySQL treats NULLs as distinct): the
     * save action writes under a lock on the report row instead.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_line_breakdowns')) {
            throw new RuntimeException('monthly_report_line_breakdowns already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_line_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_line_id');
            $table->unsignedBigInteger('monthly_report_line_share_id')->nullable();
            $table->string('gender', 10);
            $table->decimal('value', 14, 2)->nullable();
            $table->decimal('numerator', 12, 2)->nullable();
            $table->decimal('denominator', 12, 2)->nullable();
            $table->decimal('disabled_value', 14, 2)->nullable();
            $table->decimal('disabled_numerator', 12, 2)->nullable();
            $table->decimal('disabled_denominator', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['monthly_report_line_id', 'monthly_report_line_share_id', 'gender'], 'monthly_report_line_breakdowns_line_share_gender_unique');
            $table->foreign('monthly_report_line_id', 'monthly_report_line_breakdowns_line_foreign')
                ->references('id')->on('monthly_report_lines')->cascadeOnDelete();
            $table->foreign('monthly_report_line_share_id', 'monthly_report_line_breakdowns_share_foreign')
                ->references('id')->on('monthly_report_line_shares')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_line_breakdowns');
    }
};
