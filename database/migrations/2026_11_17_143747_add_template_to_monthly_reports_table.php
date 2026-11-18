<?php

declare(strict_types=1);

use App\Console\Commands\TemplateBackfillMonthlyReports;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-03, step 1 (D-76, D-80): a monthly report gets its template
     * (nullable for now) and existing reports are backfilled with their
     * project's single template. Step 2 tightens the column. Pre-checks run
     * before any DDL: MySQL does not roll DDL back.
     */
    public function up(): void
    {
        if (Schema::hasColumn('monthly_reports', 'report_template_id')) {
            throw new RuntimeException('monthly_reports.report_template_id already exists; nothing was changed.');
        }

        $backfill = new TemplateBackfillMonthlyReports;
        $plan = $backfill->plan();
        $unmapped = $backfill->unmappedIds($plan);

        if ($unmapped !== []) {
            throw new RuntimeException('Monthly reports whose project has no single template: '.implode(', ', $unmapped).'; nothing was changed.');
        }

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('report_template_id')->nullable()->after('slug');

            $table->index('report_template_id', 'monthly_reports_report_template_id_index');
            $table->foreign('report_template_id', 'monthly_reports_report_template_id_foreign')
                ->references('id')->on('report_templates')->restrictOnDelete();
        });

        $backfill->apply($plan);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('monthly_reports', 'report_template_id')) {
            return;
        }

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->dropForeign('monthly_reports_report_template_id_foreign');
            $table->dropIndex('monthly_reports_report_template_id_index');
            $table->dropColumn('report_template_id');
        });
    }
};
