<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who made each status move, and whether it was a correction of a
     * terminal status (contracts.md §7, T04). Older notes keep a null actor.
     */
    public function up(): void
    {
        if (Schema::hasColumn('proposal_status_notes', 'is_correction') || Schema::hasColumn('proposal_status_notes', 'changed_by')) {
            throw new RuntimeException('proposal_status_notes already has is_correction or changed_by; refusing to run twice.');
        }

        Schema::table('proposal_status_notes', function (Blueprint $table) {
            $table->boolean('is_correction')->default(false);
            $table->unsignedBigInteger('changed_by')->nullable();

            $table->foreign('changed_by', 'proposal_status_notes_changed_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_status_notes', function (Blueprint $table) {
            $table->dropForeign('proposal_status_notes_changed_by_foreign');
            $table->dropColumn(['is_correction', 'changed_by']);
        });
    }
};
