<?php

declare(strict_types=1);

use App\Support\DefaultEvidenceTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five starting evidence types, created here as well as in the seeder so
 * production gets them without a seeder run. DefaultEvidenceTypes::ensure()
 * is the one idempotent rule both use.
 *
 * The test suite migrates a fresh database for every test and asserts on
 * evidence type lists and counts, so it skips the insert and seeds through
 * EvidenceTypeSeeder instead (the same rule as the default sectors).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            DefaultEvidenceTypes::ensure();
        }
    }

    /**
     * Removes only the defaults, by their Arabic name. No table refers to
     * evidence types yet; once T33 slice 2 and T17 add theirs, this must also
     * skip the types they use (as the sectors migration skips used sectors).
     */
    public function down(): void
    {
        DB::table('evidence_types')
            ->whereIn('name_ar', array_column(DefaultEvidenceTypes::all(), 'name_ar'))
            ->delete();
    }
};
