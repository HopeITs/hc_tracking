<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A donor report composed from validated monthly figures (contracts
     * §14.2): who reads it, the period it covers, the frozen snapshot, its
     * version and who is responsible for delivering it. Every column is
     * nullable or defaulted, so the hand-entered reports stay as they are.
     *
     * Versions of one report share (agreement, type, period); the unique
     * index is the backstop when two people regenerate at once.
     */
    public function up(): void
    {
        foreach (['audience', 'period_type', 'period_start', 'period_end', 'snapshot', 'version', 'responsible_employee_id', 'notes'] as $column) {
            if (Schema::hasColumn('donor_reports', $column)) {
                throw new RuntimeException("donor_reports.{$column} already exists; this migration has partly run.");
            }
        }

        Schema::table('donor_reports', function (Blueprint $table) {
            $table->string('audience', 20)->default('donor')->after('type');
            $table->string('period_type', 20)->nullable()->after('audience');
            $table->date('period_start')->nullable()->after('period_type');
            $table->date('period_end')->nullable()->after('period_start');
            $table->json('snapshot')->nullable()->after('submitted_on');
            $table->unsignedSmallInteger('version')->default(1)->after('snapshot');
            $table->unsignedBigInteger('responsible_employee_id')->nullable()->after('version');
            $table->text('notes')->nullable()->after('responsible_employee_id');

            $table->index(['donor_proposal_id', 'type', 'period_start', 'period_end'], 'donor_reports_agreement_period_index');
            $table->unique(['donor_proposal_id', 'type', 'period_start', 'period_end', 'version'], 'donor_reports_agreement_period_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('donor_reports', function (Blueprint $table) {
            $table->dropUnique('donor_reports_agreement_period_version_unique');
            $table->dropIndex('donor_reports_agreement_period_index');
            $table->dropColumn(['audience', 'period_type', 'period_start', 'period_end', 'snapshot', 'version', 'responsible_employee_id', 'notes']);
        });
    }
};
