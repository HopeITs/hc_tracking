<?php

namespace Database\Seeders;

use App\Models\Donor;
use Illuminate\Database\Seeder;

/**
 * The organisation's real donors.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is real
 * fundraising data (actual funder contacts and notes), not demo data every
 * fresh dev/test database should get. Run it explicitly, against the
 * environment that should actually hold these records:
 *
 *     php artisan db:seed --class=RealDonorsSeeder
 *
 * `firstOrCreate` on `name` makes re-running it a no-op for donors already
 * inserted, so it is safe to run again after adding a new row here.
 */
class RealDonorsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->donors() as $donor) {
            Donor::query()->firstOrCreate(
                ['name' => $donor['name']],
                $donor,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function donors(): array
    {
        return [
            [
                'name' => 'Caritas Poland',
                'type' => 'foundation',
                'contact_person' => 'Radoslow / Yousef Hazboun / Dominik Derlicki / Anna szancilo / erik',
                'email' => 'rsterna@caritas.org.pl / yhazboun@caritas.org.pl / dderlicki@caritas.org.pl',
                'phone' => null,
                'country' => 'Poland',
                'notes' => 'Responsible for the financial matters / Projects Coordinator - Lebanon/Syria / vice director',
            ],
            [
                'name' => 'L\'Oeuvre D\'Orients',
                'type' => 'foundation',
                'contact_person' => 'Pascal GOLLNISCH / Vincent Cayol / Vincent Gelot / Fabrice de Boisanger',
                'email' => 'pgollnisch@oeuvre-orient.fr / vcayol@oeuvre-orient.fr / vgelot@oeuvre-orient.fr',
                'phone' => '+33(0)777680074',
                'country' => 'France',
                'notes' => 'director / vice director / Directeur pays de l’Œuvre d’Orient',
            ],
            [
                'name' => 'Mennonite Central Committee',
                'type' => 'foundation',
                'contact_person' => 'Georgina Gavrus / Petra Antoun / Huda Houssari',
                'email' => 'lebanonrep@mcc.org / petraantoun@mcc.org',
                'phone' => '9613654106',
                'country' => null,
                'notes' => 'MCC Country Representative for Lebanon, Syria and Iraq',
            ],
            [
                'name' => 'Hungary Embassy & Hungary Help',
                'type' => 'bilateral',
                'contact_person' => 'Péter CSULÁK / Youssef Fakhouri / peter Zelei',
                'email' => 'pcsulak@mfa.gov.hu / youssef.fakhouri@hungaryhelps.gov.hu',
                'phone' => null,
                'country' => 'Hungary',
                'notes' => 'Consul / Regional Project Manager (Middle East)',
            ],
            [
                'name' => 'Pro Terra Sancta',
                'type' => 'foundation',
                'contact_person' => 'Giacomo Gentile / Jean Francios / Ayham Khouly',
                'email' => 'g.gentile@proterrasancta.org',
                'phone' => '+393332029050',
                'country' => 'Italy',
                'notes' => 'coordinator aleppo / Project Manager',
            ],
        ];
    }
}
