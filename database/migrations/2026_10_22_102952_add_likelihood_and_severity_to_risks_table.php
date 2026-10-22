<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How likely a risk is and how hard it would hit, each 1 (low) to 3
     * (high), for the Desk's 3×3 risk heat map (user decision 2026-10-22).
     * Null means not rated yet: the risk stays in the list but off the map.
     */
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->unsignedTinyInteger('likelihood')->nullable()->after('happened');
            $table->unsignedTinyInteger('severity')->nullable()->after('likelihood');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropColumn(['likelihood', 'severity']);
        });
    }
};
