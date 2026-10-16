<?php

declare(strict_types=1);

use App\Support\DefaultEvidenceTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * Removes only the defaults, by their Arabic name, and skips the types a
     * report template line requires (as the sectors migration skips used
     * sectors). T17 adds `monthly_report_files` here.
     */
    public function down(): void
    {
        DB::table('evidence_types')
            ->whereIn('name_ar', array_column(DefaultEvidenceTypes::all(), 'name_ar'))
            ->when(Schema::hasTable('report_template_line_evidence_types'), fn ($query) => $query->whereNotExists(
                fn ($used) => $used->select(DB::raw(1))
                    ->from('report_template_line_evidence_types')
                    ->whereColumn('report_template_line_evidence_types.evidence_type_id', 'evidence_types.id'),
            ))
            ->delete();
    }
};
