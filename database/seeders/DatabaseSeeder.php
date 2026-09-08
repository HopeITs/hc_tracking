<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
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
        User::factory()->role(UserRole::Admin)->create([
            'name' => 'Demo Administrator',
            'email' => 'test@example.com',
        ]);

        $this->call([
            CoreDataSeeder::class,
            ProgrammeSeeder::class,
            DeliverySeeder::class,
            MealSeeder::class,
            FinanceSeeder::class,
        ]);
    }
}
