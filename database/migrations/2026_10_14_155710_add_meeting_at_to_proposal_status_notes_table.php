<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The review meeting, kept on the note that opened the review stage
     * (contracts.md §7, T07). Every other note leaves it null.
     */
    public function up(): void
    {
        if (Schema::hasColumn('proposal_status_notes', 'meeting_at')) {
            throw new RuntimeException('proposal_status_notes already has meeting_at; refusing to run twice.');
        }

        Schema::table('proposal_status_notes', function (Blueprint $table): void {
            $table->dateTime('meeting_at')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_status_notes', function (Blueprint $table): void {
            $table->dropColumn('meeting_at');
        });
    }
};
