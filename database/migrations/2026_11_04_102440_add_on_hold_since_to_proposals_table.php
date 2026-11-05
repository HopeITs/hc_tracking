<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «On hold» stops being a status and becomes a flag (D-58, W-06): a proposal
 * put on hold keeps its status and carries the date it was held here, beside
 * the `status_remind_on` the reminder already reads. Set only while the
 * status is `submitted` or `concept note submitted`, and written only by
 * SetProposalOnHold / ClearProposalOnHold (contracts.md §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('proposals', 'on_hold_since')) {
            return;
        }

        Schema::table('proposals', function (Blueprint $table): void {
            $table->date('on_hold_since')->nullable()->after('status_remind_on');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('proposals', 'on_hold_since')) {
            return;
        }

        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropColumn('on_hold_since');
        });
    }
};
