<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The deadline a report gets when it is returned after its original
     * deadline (the next working day after the return). Null keeps the
     * month's own deadline (MonthlyReportPeriods::dueDate()).
     */
    public function up(): void
    {
        Schema::table('monthly_reports', function (Blueprint $table): void {
            $table->date('revised_due_on')->nullable()->after('return_reason');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_reports', function (Blueprint $table): void {
            $table->dropColumn('revised_due_on');
        });
    }
};
