<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fundraising follow-up on a donor: when we last spoke, and what we do next.
 *
 * All three are nullable with no default — a donor recorded from a grant
 * letter has never been contacted by anyone here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->date('last_contacted_on')->nullable()->after('notes');
            $table->string('next_step')->nullable()->after('last_contacted_on');
            $table->date('next_step_due_on')->nullable()->after('next_step');

            $table->index('next_step_due_on');
        });
    }

    public function down(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->dropIndex(['next_step_due_on']);
            $table->dropColumn(['last_contacted_on', 'next_step', 'next_step_due_on']);
        });
    }
};
