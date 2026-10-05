<?php

namespace Database\Seeders;

use App\Models\Strategy;
use Illuminate\Database\Seeder;

/**
 * The country strategies programs are delivered against.
 *
 * Safe to re-run: a strategy is matched by name and never duplicated.
 */
class StrategySeeder extends Seeder
{
    /**
     * Seed the strategies.
     */
    public function run(): void
    {
        $strategies = [
            'Country Strategy 2021-2025' => [2021, 2025],
            'Country Strategy 2026-2030' => [2026, 2030],
        ];

        foreach ($strategies as $name => [$startYear, $endYear]) {
            Strategy::query()->firstOrCreate(
                ['name' => $name],
                ['start_year' => $startYear, 'end_year' => $endYear],
            );
        }
    }
}
