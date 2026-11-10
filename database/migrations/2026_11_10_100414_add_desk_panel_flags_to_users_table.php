<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which Desk panels a person pinned to the top (in the order they pinned
     * them) and which they folded into a small tile, as
     * {"pinned": ["needs", ...], "folded": ["tasks", ...]}. Read through
     * App\Support\Desk\Panels::flagsFor(), which keeps only panels on the Desk.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('desk_panel_flags')->nullable()->after('desk_quick_access');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('desk_panel_flags');
        });
    }
};
