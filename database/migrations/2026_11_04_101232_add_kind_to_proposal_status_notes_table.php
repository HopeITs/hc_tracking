<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tell transitions, corrections and negotiation notes apart (W-07, D-59).
     */
    public function up(): void
    {
        if (Schema::hasColumn('proposal_status_notes', 'kind')) {
            throw new RuntimeException('proposal_status_notes.kind already exists; nothing was changed.');
        }

        Schema::table('proposal_status_notes', function (Blueprint $table) {
            $table->string('kind', 20)->default('transition');
        });

        $this->backfillKinds();
    }

    /**
     * Notes written by a status correction become `correction`.
     */
    public function backfillKinds(): void
    {
        DB::table('proposal_status_notes')->where('is_correction', true)->update(['kind' => 'correction']);
    }

    public function down(): void
    {
        Schema::table('proposal_status_notes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
