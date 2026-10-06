<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Blade;

/**
 * A tranche as the components receive it: saved, re-read so every column is
 * hydrated, with its account loaded.
 */
function componentTranche(array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create($attributes)->fresh()->load('account');
}

test('a settled tranche row prints every money column exactly', function () {
    $tranche = componentTranche([
        'account_id' => Account::factory()->create(['account_name' => 'Operations USD'])->id,
        'payment_amount' => '250000.00',
        'amount_transfered_currency' => Currency::Usd,
        'fees' => '450.00',
        'fees_currency' => Currency::Usd,
        'exchange_rate' => '1.000000',
        'amount_received' => '249800.00',
        'net_amount_received' => '249350.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => '2026-04-01',
        'transfer_date' => '2026-04-03',
        'receive_date' => '2026-04-08',
        'ar_status' => ArStatus::Received,
    ]);

    $html = Blade::render('<table><tbody><x-funding.tranche-row :transfer="$tranche" /></tbody></table>', ['tranche' => $tranche]);

    expect($html)->toContain('wire:key="transfer-'.$tranche->id.'"')
        ->and($html)->toContain('data-transfer-state="received"')
        ->and($html)->toContain('Operations USD')
        ->and($html)->toContain('250,000.00')
        ->and($html)->toContain('450.00')
        ->and($html)->toContain('249,800.00')
        ->and($html)->toContain('249,350.00')
        ->and($html)->toContain('−650.00')
        ->and($html)->toContain('Landed in 5 days');
});

test('a pending tranche shows dashes for what has not happened, never its prefilled net', function () {
    $tranche = componentTranche([
        'payment_amount' => '1000.00',
        'amount_received' => '777.00',
        'net_amount_received' => '777.00',
        'scheduled_date' => now()->addMonth()->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);

    $html = Blade::render('<table><tbody><x-funding.tranche-row :transfer="$tranche" /></tbody></table>', ['tranche' => $tranche]);

    expect($html)->toContain('data-transfer-state="upcoming"')
        ->and($html)->toContain('1,000.00')
        ->and($html)->not->toContain('777.00')
        ->and($html)->toContain('No account');
});

test('a received tranche with no net reads not recorded, and one in another currency reads converted', function () {
    $missing = componentTranche([
        'payment_amount' => '300.00',
        'amount_received' => null,
        'net_amount_received' => null,
        'ar_status' => ArStatus::Received,
    ]);
    $converted = componentTranche([
        'payment_amount' => '300.00',
        'amount_transfered_currency' => Currency::Usd,
        'amount_received' => '280.00',
        'net_amount_received' => '275.00',
        'amount_received_currency' => Currency::Eur,
        'ar_status' => ArStatus::Received,
    ]);

    $render = fn (TransfersTracking $tranche): string => Blade::render('<table><tbody><x-funding.tranche-row :transfer="$tranche" /></tbody></table>', ['tranche' => $tranche]);

    expect($render($missing))->toContain('Not recorded yet')
        ->and($render($missing))->toContain('data-state="net-missing"')
        ->and($render($converted))->toContain('Converted')
        ->and($render($converted))->toContain('275.00')
        ->and($render($converted))->toContain('EUR');
});

test('the row prints its lead and actions slots around the shared cells', function () {
    $tranche = componentTranche(['ar_status' => ArStatus::Pending]);

    $html = Blade::render(
        '<table><tbody><x-funding.tranche-row :transfer="$tranche"><x-slot:lead><td>LEAD CELL</td></x-slot:lead><x-slot:actions><td>ACTION CELL</td></x-slot:actions></x-funding.tranche-row></tbody></table>',
        ['tranche' => $tranche],
    );

    expect(strpos($html, 'LEAD CELL'))->toBeLessThan(strpos($html, 'data-state='))
        ->and(strpos($html, 'ACTION CELL'))->toBeGreaterThan(strpos($html, 'data-cell="difference"'));
});

test('a group row carries its label and a subtotal line per currency', function () {
    $lines = [
        ['currency' => 'USD', 'tranches' => 26, 'sent' => 2600.0, 'fees' => 0.0, 'gross' => 0.0, 'net' => 0.0, 'shortfall' => 0.0, 'overage' => 0.0],
    ];

    $html = Blade::render('<table><tbody><x-funding.tranche-group label="Alpha Fund" group="7" :lines="$lines" :lead="1" /></tbody></table>', ['lines' => $lines]);

    expect($html)->toContain('data-group="7"')
        ->and($html)->toContain('register-group')
        ->and($html)->toContain('Alpha Fund')
        ->and($html)->toContain('26 tranches')
        ->and($html)->toContain('2,600.00');
});

test('the head offers a sort button per sortable column only when asked', function () {
    $sortable = Blade::render('<table><thead><tr><x-funding.tranche-head sortable active="amount" dir="desc" /></tr></thead></table>');
    $plain = Blade::render('<table><thead><tr><x-funding.tranche-head :actions="false" /></tr></thead></table>');

    expect($sortable)->toContain('wire:click="sortRegister(\'amount\')"')
        ->and($sortable)->toContain('aria-sort="descending"')
        ->and(substr_count($sortable, '<th scope='))->toBe(13)
        ->and($plain)->not->toContain('sortRegister')
        ->and(substr_count($plain, '<th scope='))->toBe(12);
});

test('the tranche table prints every tranche and an identity column when asked, with no totals row', function () {
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => Proposal::factory()->create(['name' => 'Water Response'])->id,
        'external_project_number' => 'WR-9',
        'currency' => Currency::Usd,
    ]);
    $tranches = collect([
        componentTranche(['donor_proposal_id' => $agreement->id, 'payment_amount' => '400.00', 'amount_transfered_currency' => Currency::Usd, 'fees' => '5.00', 'fees_currency' => Currency::Usd, 'amount_received' => '398.00', 'net_amount_received' => '390.00', 'amount_received_currency' => Currency::Usd, 'ar_status' => ArStatus::Received]),
        componentTranche(['donor_proposal_id' => $agreement->id, 'payment_amount' => '100.00', 'amount_transfered_currency' => Currency::Usd, 'ar_status' => ArStatus::Pending, 'transfer_date' => null]),
    ])->each(fn (TransfersTracking $tranche) => $tranche->setRelation('donorProposal', $agreement->load('proposal')));

    $html = Blade::render('<x-funding.tranche-table :transfers="$tranches" caption="Tranches" party="proposal" />', ['tranches' => $tranches]);

    expect($html)->toContain('data-register')
        ->and($html)->toContain('Water Response')
        ->and($html)->toContain('WR-9')
        ->and(substr_count($html, 'data-transfer="'))->toBe(2)
        ->and($html)->not->toContain('data-register-total')
        ->and($html)->not->toContain('<tfoot>')
        ->and($html)->toContain('400.00')
        ->and($html)->not->toContain('data-test="edit-transfer-');
});
