<?php

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Seeder;

/**
 * Every real-data seeder in dependency order, so the organisation's actual
 * records load with one command:
 *
 *     php artisan db:seed --class=RealDataSeeder
 *
 * Deliberately NOT part of DatabaseSeeder: this is real programme, funding
 * and banking data, not demo data every fresh dev/test database should get.
 * Run it on a database that already has its users (DatabaseSeeder locally,
 * `php artisan hr:sync-users` in production), because RealTasksSeeder picks
 * an existing user as the tasks' creator.
 *
 * Donors and projects come before the proposals that fund them, projects
 * before their milestones, risks and results, proposals before the results
 * that target them, and milestones before their tasks. Each seeder is a no-op
 * for rows it already inserted, so re-running is safe. Runs with activity
 * recording off, same as DatabaseSeeder — see .ai/rules/database-seeders.md.
 */
class RealDataSeeder extends Seeder
{
    /**
     * Seed the organisation's real records.
     */
    public function run(): void
    {
        Activity::withoutRecording(function (): void {
            $this->call([
                RealAccountsSeeder::class,
                RealDonorsSeeder::class,
                RealProjectsSeeder::class,
                RealMilestonesSeeder::class,
                RealProposalsSeeder::class,
                RealResultsSeeder::class,
                RealRisksSeeder::class,
                RealTasksSeeder::class,
            ]);
        });
    }
}
