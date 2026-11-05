<?php

declare(strict_types=1);

use App\Support\DefaultRiskLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MEAL's starting risk list, supplied after `seed_risk_library` had already
 * run with an empty `DefaultRiskLibrary::all()` (W-09, Start state 3). That
 * migration cannot run twice, so the list needs its own pass here; every
 * database reaches the same place because `ensure()` is idempotent and
 * inserts only the entries no row carries yet, in either language.
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
