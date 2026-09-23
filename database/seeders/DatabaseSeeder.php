<?php

namespace Database\Seeders;

use App\Models\Activity;
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
     *
     * Runs with activity recording off: seeded data is history, not news, so
     * it must never produce activity or notifications.
     */
    public function run(): void
    {
        Activity::withoutRecording(function (): void {
            $this->call([
                RolesAndPermissionsSeeder::class,
                CoreDataSeeder::class,
                ProgrammeSeeder::class,
                DeliverySeeder::class,
                MealSeeder::class,
                FinanceSeeder::class,
            ]);
        });
    }
}
