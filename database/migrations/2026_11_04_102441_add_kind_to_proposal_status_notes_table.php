<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a status note records (`StatusNoteKind`), so the Status timeline tells
 * a move apart from a hold, a correction or a donor negotiation.
 *
 * The column belongs to W-07's contract; W-06 adds it because it needs
 * `on_hold` / `off_hold` first, with W-07's definition exactly, so W-07 and
 * W-08 reuse it instead of adding one of their own.
 *
 * Every existing row is a plain move, which the default already says; the
 * backfill only re-labels the corrections T04 marked with `is_correction`,
 * which stays as it is for history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('proposal_status_notes', 'kind')) {
            return;
        }

        Schema::table('proposal_status_notes', function (Blueprint $table): void {
            $table->string('kind', 20)->default('transition')->after('context');
        });

        DB::table('proposal_status_notes')->where('is_correction', true)->update(['kind' => 'correction']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('proposal_status_notes', 'kind')) {
            return;
        }

        Schema::table('proposal_status_notes', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
