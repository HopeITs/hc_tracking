<?php

namespace Database\Seeders;

use App\Enums\UserRole;
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
        User::factory(4)->role(UserRole::Manager)->create();
        User::factory(2)->role(UserRole::Meal)->create();
        User::factory(6)->role(UserRole::Officer)->create();

        foreach (['Damascus', 'Aleppo', 'Homs', 'Hama', 'Idlib', 'Latakia', 'Raqqa', 'Deir ez-Zor'] as $city) {
            City::firstOrCreate(['name' => $city]);
        }

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
            Donor::firstOrCreate(['name' => $donor]);
        }
    }
}
