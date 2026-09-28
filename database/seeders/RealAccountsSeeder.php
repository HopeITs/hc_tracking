<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The organisation's real bank accounts.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is production
 * financial data (real account numbers, IBANs, authorized signatories), not
 * demo data every fresh dev/test database should get. Run it explicitly,
 * against the environment that should actually hold these records:
 *
 *     php artisan db:seed --class=RealAccountsSeeder
 *
 * or with every other real seeder, in dependency order, via RealDataSeeder.
 *
 * `firstOrCreate` on `account_number` makes re-running it a no-op for
 * accounts already inserted, so it is safe to run again after adding a new
 * row here.
 */
class RealAccountsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $account) {
            Account::query()->firstOrCreate(
                ['account_number' => $account['account_number']],
                $account,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accounts(): array
    {
        return [
            [
                'bank_name' => 'Fransbank',
                'currency' => 'SYP',
                'account_name' => 'Christian Hope Center',
                'authorized_person' => 'Fredy Youssef, Hanna Jallouf, Shadi Makdessi',
                'account_number' => '21.13.0000803.11',
                'iban' => null,
                'swift_code' => null,
                'country' => 'Syria',
                'po_box_street' => 'Al-Azizieh',
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Fransbank',
                'currency' => 'USD',
                'account_name' => 'Christian Hope Center',
                'authorized_person' => 'Fredy Youssef, Hanna Jallouf, Shadi Makdessi',
                'account_number' => '701025',
                'iban' => null,
                'swift_code' => null,
                'country' => 'Syria',
                'po_box_street' => 'Al-Azizieh',
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Bank Of Beirut',
                'currency' => 'USD',
                'account_name' => 'SABRO TOBO',
                'authorized_person' => 'Maurice El Beaino',
                'account_number' => '1140Z06926200',
                'iban' => 'LB 88 0075 0000 0001 140Z 0692 6200',
                'swift_code' => 'BABELBBE',
                'country' => 'Lebanon',
                'po_box_street' => 'Nahr Branch, Mar Mikhael Street Beirut',
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Bank Of Beirut',
                'currency' => 'EUR',
                'account_name' => 'SABRO TOBO',
                'authorized_person' => 'Maurice El Beaino',
                'account_number' => '4040Z06926201',
                'iban' => null,
                'swift_code' => 'BABELBBE',
                'country' => 'Lebanon',
                'po_box_street' => 'Nahr Branch, Mar Mikhael Street Beirut',
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Bank Of Ruifeng',
                'currency' => 'EUR',
                'account_name' => 'Corridors Trading Co., Limited',
                'authorized_person' => null,
                'account_number' => 'NRA201000409112768',
                'iban' => null,
                'swift_code' => 'ZSRBCN2S',
                'country' => 'China',
                'po_box_street' => 'No.509 North Gongren Road. Yiwu City Zhejiang Prov. China',
                'company_address' => '3507, 35Th Floor, Futian Yinzuo Building B, Yiwu City Jinhua Zhejiang China',
                'opened_at' => '2026-01-09',
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Bank Of Ruifeng',
                'currency' => 'EUR',
                'account_name' => 'MARS ORBIT TRADE LIMITED',
                'authorized_person' => null,
                'account_number' => 'NRA201000340603445',
                'iban' => null,
                'swift_code' => 'ZSRBCN2S',
                'country' => 'China',
                'po_box_street' => 'NO.509 NORTH GONGREN ROAD. YIWU CITY ZHEJIANG PROV. CHINA',
                'company_address' => 'APARTMENT 1804-55 UPPER GROUND LONDON ENGLAND UK SE1-9EY',
                'opened_at' => null,
                'closed_at' => '2026-08-31',
                'is_active' => false,
            ],
            [
                'bank_name' => 'Cash',
                'currency' => 'USD',
                'account_name' => 'Cash Account',
                'authorized_person' => null,
                'account_number' => 'CASH-USD-01',
                'iban' => null,
                'swift_code' => null,
                'country' => 'Syria',
                'po_box_street' => null,
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Bloom Bank Sal',
                'currency' => 'USD',
                'account_name' => 'Christian Assoc dev of Edu Family',
                'authorized_person' => 'Fr. Raymond Abdo',
                'account_number' => '030/02/353/2531267/1/3',
                'iban' => 'LB03001400003002353253126713',
                'swift_code' => 'BLOMLBBX',
                'country' => 'Lebanon',
                'po_box_street' => '11--1912 Riad El Solh - Beirut 1107 2807',
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
            [
                'bank_name' => 'Bloom Bank Sal',
                'currency' => 'EUR',
                'account_name' => 'Christian Assoc dev of Edu Family',
                'authorized_person' => 'Fr. Raymond Abdo',
                'account_number' => '030/04/353/2531267/1/1',
                'iban' => null,
                'swift_code' => 'BLOMLBBX',
                'country' => 'Lebanon',
                'po_box_street' => '11--1912 Riad El Solh - Beirut 1107 2807',
                'company_address' => null,
                'opened_at' => null,
                'closed_at' => null,
                'is_active' => true,
            ],
        ];
    }
}
