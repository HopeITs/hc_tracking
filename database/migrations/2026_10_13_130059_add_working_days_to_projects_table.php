<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sorted ISO weekday numbers (1 = Monday … 7 = Sunday); null means the
     * global working calendar applies.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('working_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('working_days');
        });
    }
};
