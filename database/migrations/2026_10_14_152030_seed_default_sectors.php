<?php

declare(strict_types=1);

use App\Support\DefaultSectors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The six starting sectors (D-47), created here as well as in the seeder so
 * production gets them without a seeder run. DefaultSectors::ensure() is the
 * one idempotent rule both use.
 *
 * The test suite migrates a fresh database for every test and asserts on
 * sector lists and counts, so it skips the insert and seeds through
 * CoreDataSeeder instead (the same rule as the internal donor).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            DefaultSectors::ensure();
        }
    }

    /**
     * Removes only the defaults no project uses; the rest stay, because
     * `project_sector.sector_id` restricts on delete.
     */
    public function down(): void
    {
        DB::table('sectors')
            ->whereIn('name_en', array_column(DefaultSectors::all(), 'name_en'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('project_sector')->whereColumn('project_sector.sector_id', 'sectors.id'))
            ->delete();
    }
};
