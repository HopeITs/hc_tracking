<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The optional "Update risks" line of a monthly report.
     */
    public function up(): void
    {
        if (Schema::hasColumn('monthly_reports', 'updates_risks')) {
            throw new RuntimeException('monthly_reports already has updates_risks; nothing was changed.');
        }

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->boolean('updates_risks')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('monthly_reports', 'updates_risks')) {
            Schema::table('monthly_reports', function (Blueprint $table) {
                $table->dropColumn('updates_risks');
            });
        }
    }
};
