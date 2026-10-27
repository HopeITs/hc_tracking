<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The panels each person keeps behind the top bar's Panels button, per
     * page, as {"projects.index": [{"panel": "risks", "size": "L"}, ...]}.
     * A page with no entry gets its default set (App\Support\Desk\Panels).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('page_panels')->nullable()->after('desk_layout');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('page_panels');
        });
    }
};
