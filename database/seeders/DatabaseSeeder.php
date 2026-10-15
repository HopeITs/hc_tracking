<?php

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The domain seeders run in dependency order: master data, then the
     * programme layer everything else hangs off, then the three layers that
     * read from it.
     *
     * Every seeder runs from here. The domain and real-data seeders run with
     * model events and activity recording off: seeded data is history, not
     * news, so it must never produce activity or notifications.
     *
     * NotificationDemoSeeder comes last and outside that quiet block: it
     * exists to exercise the activity and notification pipeline, so events
     * and recording must be on for it.
     */
    public function run(): void
    {
        Model::withoutEvents(fn () => Activity::withoutRecording(function (): void {
            $this->call([
                RolesAndPermissionsSeeder::class,
                CoreDataSeeder::class,
                StrategySeeder::class,
                ProgrammeSeeder::class,
                DemoDeliverySeeder::class,
                DeliverySeeder::class,
                MealSeeder::class,
                FinanceSeeder::class,
                RealDataSeeder::class,
                SectorSeeder::class,
            ]);
        }));

        $this->call(NotificationDemoSeeder::class);
    }
}
