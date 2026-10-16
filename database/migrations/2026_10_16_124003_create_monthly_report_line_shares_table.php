<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One share per covering target (plan_indicator) of a line, in both
     * donor attribution modes (contracts §13, D-46). In `shared` every share
     * carries the line's figures; in `split` the line is the sum of shares.
     * Each share is mirrored into one `reports` row.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_line_shares')) {
            throw new RuntimeException('monthly_report_line_shares already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_line_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_line_id');
            $table->unsignedBigInteger('plan_indicator_id');
            $table->decimal('value', 14, 2)->nullable();
            $table->decimal('numerator', 12, 2)->nullable();
            $table->decimal('denominator', 12, 2)->nullable();
            $table->unsignedInteger('fixed_denominator')->nullable();
            $table->boolean('not_measured')->default(false);
            $table->timestamps();

            $table->unique(['monthly_report_line_id', 'plan_indicator_id'], 'monthly_report_line_shares_line_target_unique');
            $table->foreign('monthly_report_line_id', 'monthly_report_line_shares_line_foreign')
                ->references('id')->on('monthly_report_lines')->cascadeOnDelete();
            $table->foreign('plan_indicator_id', 'monthly_report_line_shares_plan_indicator_foreign')
                ->references('id')->on('plan_indicators')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_line_shares');
    }
};
