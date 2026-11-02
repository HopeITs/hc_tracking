<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Desk's "My notes" card: private free text, only its owner reads it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('desk_notes')->nullable()->after('page_panels');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('desk_notes');
        });
    }
};
