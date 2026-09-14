<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Programs are owned by the HR application (hr_db.hchr_programs). Each row in
     * programs now mirrors one HR program under the same id, with `name` kept as
     * a copy refreshed from HR. A program arrives from HR before anyone has
     * linked it, so its strategy and manager become optional.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('strategy_id')->nullable()->change();
            $table->foreignId('manager_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Fails once any program is left without a strategy or manager.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('strategy_id')->nullable(false)->change();
            $table->foreignId('manager_id')->nullable(false)->change();
        });
    }
};
