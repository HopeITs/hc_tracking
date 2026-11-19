<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pure view preferences per page (the proposal page's framework layout,
     * open sections and similar), keyed by page. Never record data.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'page_prefs')) {
            throw new RuntimeException('users.page_prefs already exists; nothing was changed.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('page_prefs')->nullable()->after('page_panels');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('page_prefs');
        });
    }
};
