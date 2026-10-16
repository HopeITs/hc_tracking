<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly report figures reach `reports` as mirrors, one row per line
     * share, so PlanIndicator::achievedValue() stays the one achievement
     * definition (contracts §13). A ratio carries its numerator and
     * denominator; a line marked not measured has a NULL value.
     *
     * Rollback caveat: down() makes `value` NOT NULL again, which fails once
     * any NULL row exists.
     */
    public function up(): void
    {
        foreach (['numerator', 'denominator', 'monthly_report_id', 'monthly_report_line_share_id'] as $column) {
            if (Schema::hasColumn('reports', $column)) {
                throw new RuntimeException("reports.{$column} already exists; this migration has partly run.");
            }
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->decimal('value', 12, 2)->nullable()->change();
            $table->decimal('numerator', 12, 2)->nullable()->after('value');
            $table->decimal('denominator', 12, 2)->nullable()->after('numerator');
            $table->unsignedBigInteger('monthly_report_id')->nullable()->after('plan_indicator_id');
            $table->unsignedBigInteger('monthly_report_line_share_id')->nullable()->after('monthly_report_id');

            $table->foreign('monthly_report_id', 'reports_monthly_report_id_foreign')
                ->references('id')->on('monthly_reports')->cascadeOnDelete();
            $table->foreign('monthly_report_line_share_id', 'reports_monthly_report_line_share_id_foreign')
                ->references('id')->on('monthly_report_line_shares')->cascadeOnDelete();
            $table->unique('monthly_report_line_share_id', 'reports_monthly_report_line_share_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign('reports_monthly_report_id_foreign');
            $table->dropForeign('reports_monthly_report_line_share_id_foreign');
            $table->dropUnique('reports_monthly_report_line_share_id_unique');
            $table->dropIndex('reports_monthly_report_id_foreign');
            $table->dropColumn(['numerator', 'denominator', 'monthly_report_id', 'monthly_report_line_share_id']);
            $table->decimal('value', 12, 2)->nullable(false)->change();
        });
    }
};
