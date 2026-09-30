<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the guided tour first opened by itself for this person. Null means
     * it still will, on their first visit to the dashboard; after that it only
     * opens when they press the tour button. Everyone who exists today has
     * already signed in, so they start out as having seen it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tour_seen_at')->nullable()->after('notification_popups');
        });

        DB::table('users')->update(['tour_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_seen_at');
        });
    }
};
