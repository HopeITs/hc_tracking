<?php

namespace Database\Seeders;

use App\Enums\DonorType;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Donor;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

        // Real classifications, so the donor type filter has something on more
        // than one side of it. GIZ and SIDA are bilateral agencies; UNICEF and
        // OCHA are UN bodies, recorded here as foundations for want of a
        // multilateral member — raise that with the user before adding one.
        $donors = [
            'UNICEF' => ['type' => DonorType::Foundation, 'country' => 'United States'],
            'ECHO' => ['type' => DonorType::Bilateral, 'country' => 'Belgium'],
            'GIZ' => ['type' => DonorType::Bilateral, 'country' => 'Germany'],
            'SIDA' => ['type' => DonorType::Bilateral, 'country' => 'Sweden'],
            'OCHA' => ['type' => DonorType::Foundation, 'country' => 'Switzerland'],
        ];

        foreach ($donors as $name => $profile) {
            Donor::firstOrCreate(
                ['name' => $name],
                [
                    'type' => $profile['type'],
                    'country' => $profile['country'],
                    'contact_person' => fake()->name(),
                    'email' => Str::lower('grants@'.Str::slug($name).'.org'),
                    'phone' => fake()->numerify('+## ## ### ####'),
                ],
            );
        }
    }
}
