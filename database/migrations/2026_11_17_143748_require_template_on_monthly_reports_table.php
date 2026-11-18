<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-03, step 2 (D-76): one report per template and month. The template
     * becomes required, `project_id` becomes nullable and leaves the unique
     * index; its foreign key gets a plain index of its own first, since the
     * unique stops serving it.
     */
    public function up(): void
    {
        if (DB::table('monthly_reports')->whereNull('report_template_id')->exists()) {
            throw new RuntimeException('Monthly reports without a template remain; run monthly-reports:template-backfill. Nothing was changed.');
        }

        $duplicates = DB::table('monthly_reports')
            ->select('report_template_id', 'period')
            ->groupBy('report_template_id', 'period')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Two monthly reports share a template and month: '.$duplicates->map(fn (object $row): string => "{$row->report_template_id}/{$row->period}")->implode(', ').'. Nothing was changed.');
        }

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->index('project_id', 'monthly_reports_project_id_index');
        });

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->dropUnique('monthly_reports_project_period_unique');
        });

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
            $table->unsignedBigInteger('report_template_id')->nullable(false)->change();
        });

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->unique(['report_template_id', 'period'], 'monthly_reports_template_period_unique');
        });
    }

    /**
     * Restores one report per project and month. A report with no project
     * (made after Y-03) or two reports of one project and month cannot go
     * back, so down() stops before DDL and says which.
     */
    public function down(): void
    {
        $blocking = DB::table('monthly_reports')
            ->whereNull('project_id')
            ->orWhereIn(DB::raw('(project_id, period)'), fn ($query) => $query->select('project_id', 'period')->from('monthly_reports')->whereNotNull('project_id')->groupBy('project_id', 'period')->havingRaw('COUNT(*) > 1'))
            ->orderBy('id')
            ->pluck('id');

        if ($blocking->isNotEmpty()) {
            throw new RuntimeException('Monthly reports without a project or sharing a project and month cannot go back to one report per project and month: '.$blocking->implode(', ').'. Nothing was changed.');
        }

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->dropUnique('monthly_reports_template_period_unique');
        });

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('report_template_id')->nullable()->change();
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->unique(['project_id', 'period'], 'monthly_reports_project_period_unique');
        });

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->dropIndex('monthly_reports_project_id_index');
        });
    }
};
