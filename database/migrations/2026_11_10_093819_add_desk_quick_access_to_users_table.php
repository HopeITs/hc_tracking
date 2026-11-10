<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pages and actions each person keeps behind the Desk's Quick access
     * button, in their order, as the Ask bar's row keys:
     * ["go:projects.index", "new:task", ...]. Read through
     * App\Support\Desk\QuickAccess, which drops what they may no longer open.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('desk_quick_access')->nullable()->after('page_panels');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('desk_quick_access');
        });
    }
};
