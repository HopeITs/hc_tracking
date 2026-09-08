<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Donor;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Master data every other seeder builds on: staff, cities, strategies, donors.
 */
class CoreDataSeeder extends Seeder
{
    /**
     * Seed the master data tables.
     */
    public function run(): void
    {
        User::factory(12)->create();

        City::factory(8)->create();

        Strategy::factory()->create([
            'name' => 'Country Strategy 2021-2025',
            'start_year' => 2021,
            'end_year' => 2025,
        ]);

        Strategy::factory()->create([
            'name' => 'Country Strategy 2026-2030',
            'start_year' => 2026,
            'end_year' => 2030,
        ]);

        foreach (['UNICEF', 'ECHO', 'GIZ', 'SIDA', 'OCHA'] as $donor) {
            Donor::factory()->create(['name' => $donor]);
        }
    }
}
