<?php

namespace Database\Seeders;

use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\HrUser;
use App\Models\Strategy;
use App\Models\User;
use Database\Seeders\Concerns\PicksHrRecords;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Master data every other seeder builds on: staff, strategies, donors.
 *
 * Staff are the HR accounts: every one gets a tracking user (Officer, or Admin
 * for auth.hr_admin_emails). Cities come from the HR database too.
 */
class CoreDataSeeder extends Seeder
{
    use PicksHrRecords;

    /**
     * Seed the master data tables.
     */
    public function run(): void
    {
        if (HrUser::query()->doesntExist()) {
            $this->createHrAccounts();
        }

        User::syncFromHrAccounts();

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

    /**
     * Give an empty HR database a few login accounts.
     *
     * Only the test suite's in-memory HR database is ever empty; the real `hr`
     * connection is READ ONLY and would reject these inserts.
     */
    protected function createHrAccounts(): void
    {
        foreach ($this->hrEmployeeIds()->take(6) as $employeeId) {
            DB::connection('hr')->table('users')->insert([
                'employee_id' => $employeeId,
                'email' => fake()->unique()->safeEmail(),
                'password' => Hash::make('password'),
            ]);
        }
    }
}
