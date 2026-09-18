<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The domain seeders run in dependency order: master data, then the
     * programme layer everything else hangs off, then the three layers that
     * read from it.
     */
    public function run(): void
    {

        $this->call([
            RolesAndPermissionsSeeder::class,
            CoreDataSeeder::class,
            ProgrammeSeeder::class,
            DeliverySeeder::class,
            MealSeeder::class,
            FinanceSeeder::class,
        ]);
    }
}
