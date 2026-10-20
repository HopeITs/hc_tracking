<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The panels each person arranged on their Desk, in order, as
     * [{"panel": "needs", "size": "L"}, ...]. Null means they have not
     * customised it and get their role's default layout (App\Support\Desk\Panels).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('desk_layout')->nullable()->after('locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('desk_layout');
        });
    }
};
