<?php

declare(strict_types=1);

use App\Support\DefaultRiskLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The starting risk library, created here as well as in the seeder so
 * production gets it without a seeder run. DefaultRiskLibrary::ensure() is
 * the one idempotent rule both use. The list is empty until MEAL supplies
 * it (W-09, Start state 3), so this inserted nothing: the list arrived on
 * 4 Nov 2026 and is carried by seed_risk_library_starting_list instead.
 *
 * The test suite migrates a fresh database for every test, so it skips the
 * insert and seeds through the seeder instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            DefaultRiskLibrary::ensure();
        }
    }

    /**
     * Removes only the defaults no risk was picked from.
     */
    public function down(): void
    {
        DB::table('risk_library')
            ->whereIn('title_en', array_column(DefaultRiskLibrary::all(), 'title_en'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('risks')->whereColumn('risks.library_risk_id', 'risk_library.id'))
            ->delete();
    }
};
