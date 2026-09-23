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
            'Caritas Poland' => [
                'type' => DonorType::Foundation,
                'country' => 'Poland',
                'contact_person' => 'Radoslow / Yousef Hazboun / Dominik Derlicki / Anna szancilo / erik',
                'email' => 'rsterna@caritas.org.pl / yhazboun@caritas.org.pl / dderlicki@caritas.org.pl',
                'notes' => 'Responsible for the financial matters / Projects Coordinator - Lebanon/Syria / vice director',
            ],
            'L\'Oeuvre D\'Orients' => [
                'type' => DonorType::Foundation,
                'country' => 'France',
                'contact_person' => 'Pascal GOLLNISCH / Vincent Cayol / Vincent Gelot / Fabrice de Boisanger',
                'email' => 'pgollnisch@oeuvre-orient.fr / vcayol@oeuvre-orient.fr / vgelot@oeuvre-orient.fr',
                'phone' => '+33(0)777680074',
                'notes' => 'director / vice director / Directeur pays de l\'Œuvre d\'Orient',
            ],
            'Mennonite Central Committee' => [
                'type' => DonorType::Foundation,
                'country' => null,
                'contact_person' => 'Georgina Gavrus / Petra Antoun / Huda Houssari',
                'email' => 'lebanonrep@mcc.org / petraantoun@mcc.org',
                'phone' => '9613654106',
                'notes' => 'MCC Country Representative for Lebanon, Syria and Iraq',
            ],
            'Hungary Embassy & Hungary Help' => [
                'type' => DonorType::Bilateral,
                'country' => 'Hungary',
                'contact_person' => 'Péter CSULÁK / Youssef Fakhouri / peter Zelei',
                'email' => 'pcsulak@mfa.gov.hu / youssef.fakhouri@hungaryhelps.gov.hu',
                'notes' => 'Consul / Regional Project Manager (Middle East)',
            ],
            'Pro Terra Sancta' => [
                'type' => DonorType::Foundation,
                'country' => 'Italy',
                'contact_person' => 'Giacomo Gentile / Jean Francios / Ayham Khouly',
                'email' => 'g.gentile@proterrasancta.org',
                'phone' => '+393332029050',
                'notes' => 'coordinator aleppo / Project Manager',
            ],
        ];

        foreach ($donors as $name => $profile) {
            Donor::firstOrCreate(
                ['name' => $name],
                [
                    'type' => $profile['type'],
                    'country' => $profile['country'] ?? null,
                    'contact_person' => $profile['contact_person'] ?? fake()->name(),
                    'email' => $profile['email'] ?? Str::lower('grants@'.Str::slug($name).'.org'),
                    'phone' => $profile['phone'] ?? fake()->numerify('+## ## ### ####'),
                    'notes' => $profile['notes'] ?? null,
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
