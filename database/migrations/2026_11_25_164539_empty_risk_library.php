<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MEAL's decision of 2026-11-25: the risk library starts empty and fills
 * itself from the risk titles MEAL types. Risks keep their own names, so
 * only the link to the library entry is cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('risks')->whereNotNull('library_risk_id')->update(['library_risk_id' => null]);
        DB::table('risk_library')->delete();
    }

    public function down(): void
    {
        //
    }
};
