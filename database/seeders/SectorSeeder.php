<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\DefaultSectors;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The six D-47 sectors, and one sector for every seeded project that has
 * none, so the project list, filter and page have something to show. Runs
 * after the project seeders. Query builder and insertOrIgnore only, so it is
 * idempotent and records no activity (DatabaseSeeder also wraps it in
 * Activity::withoutRecording).
 */
class SectorSeeder extends Seeder
{
    public function run(): void
    {
        DefaultSectors::ensure();

        $sectorIds = DB::table('sectors')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();

        if ($sectorIds === []) {
            return;
        }

        $now = now();

        $rows = DB::table('projects')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('project_sector')->whereColumn('project_sector.project_id', 'projects.id'))
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->map(fn (mixed $projectId, int $index): array => [
                'project_id' => (int) $projectId,
                'sector_id' => $sectorIds[$index % count($sectorIds)],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('project_sector')->insertOrIgnore($rows);
        }
    }
}
