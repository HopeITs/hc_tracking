<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An activity's own start and end when it runs on set dates (the
     * proposal page's "Dates" schedule). Null for continuous and event
     * activities, and for every other level.
     */
    public function up(): void
    {
        if (Schema::hasColumn('results', 'starts_on')) {
            throw new RuntimeException('results.starts_on already exists; nothing was changed.');
        }

        Schema::table('results', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('activity_kind');
            $table->date('ends_on')->nullable()->after('starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
