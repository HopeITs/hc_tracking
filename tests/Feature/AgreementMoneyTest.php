<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;

beforeEach(fn () => $this->travelTo('2026-06-15 09:00:00'));

/**
 * 1,000 USD: 400 received (390 net, landed 2026-05-20), 200 overdue,
 * 100 upcoming, 50 in transit, one 10 EUR tranche.
 */
function moneyAgreement(array $attributes = []): DonorProposal
{
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd, ...$attributes]);
    $tranche = fn (array $values) => TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id, 'amount_transfered_currency' => Currency::Usd, 'amount_received_currency' => Currency::Usd,
        'transfer_date' => null, 'receive_date' => null, 'ar_status' => ArStatus::Pending, ...$values,
    ]);

    $tranche(['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    $tranche(['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    $tranche(['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);
    $tranche(['payment_amount' => '50.00', 'scheduled_date' => '2026-06-14', 'transfer_date' => '2026-06-14']);
    $tranche(['payment_amount' => '10.00', 'amount_transfered_currency' => Currency::Eur, 'scheduled_date' => '2025-03-01']);

    return $agreement;
}

function moneyRow(DonorProposal $agreement, ?int $year = null, bool $yearOnly = false): DonorProposal
{
    return DonorProposal::query()->withMoney($year, $yearOnly)->findOrFail($agreement->id);
}

test('an agreement carries every money figure from one join', function () {
    $row = moneyRow(moneyAgreement());

    expect((int) $row->money_tranches)->toBe(5)
        ->and((float) $row->money_scheduled)->toBe(750.0)
        ->and((float) $row->money_received)->toBe(390.0)
        ->and((float) $row->money_in_transit)->toBe(50.0)
        ->and((float) $row->money_overdue)->toBe(200.0)
        ->and((int) $row->money_overdue_count)->toBe(1)
        ->and((float) $row->money_upcoming)->toBe(100.0)
        ->and((int) $row->money_other_currency)->toBe(1)
        ->and((float) $row->money_unscheduled)->toBe(250.0)
        ->and((float) $row->money_outstanding)->toBe(600.0)
        ->and($row->money_next_due)->toBe('2025-03-01')
        ->and($row->money_last_received)->toBe('2026-05-20')
        ->and((float) $row->money_due_in_year)->toBe(0.0)
        ->and($row->budget)->toBe('1000.00');
});

test('an agreement with no tranche reads zero, never null', function () {
    $row = moneyRow(DonorProposal::factory()->create(['budget' => '500.00']));

    expect((float) $row->money_scheduled)->toBe(0.0)
        ->and((float) $row->money_unscheduled)->toBe(500.0)
        ->and((float) $row->money_outstanding)->toBe(500.0)
        ->and($row->money_next_due)->toBeNull();
});

test('a year adds its own figures, and year only counts nothing else', function () {
    $agreement = moneyAgreement();

    $beside = moneyRow($agreement, 2026);
    $only = moneyRow($agreement, 2025, true);

    expect((float) $beside->money_due_in_year)->toBe(750.0)
        ->and((float) $beside->money_received_in_year)->toBe(390.0)
        ->and((float) $beside->money_scheduled)->toBe(750.0)
        ->and((int) $only->money_tranches)->toBe(1)
        ->and((float) $only->money_scheduled)->toBe(0.0)
        ->and(AgreementMoney::yearBounds(2026))->toBe(['2026-01-01', '2026-12-31'])
        ->and(AgreementMoney::yearBounds(null))->toBeNull();
});

test('totals are one line per currency in SQL and in PHP alike', function () {
    moneyAgreement();
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['budget' => '300.00', 'currency' => Currency::Eur]);

    $sql = DonorProposal::query()->joinMoney()->toBase()->selectRaw(AgreementMoney::totalsSelect())
        ->groupBy('donor_proposal.currency')->orderBy('donor_proposal.currency')->get()
        ->map(fn (object $row): array => AgreementMoney::line($row))->keyBy('currency');
    $php = collect(AgreementMoney::totals(DonorProposal::query()->select('donor_proposal.*')->withMoney()->get()))->keyBy('currency');

    expect($sql->keys()->all())->toBe(['EUR', 'USD'])
        ->and($sql['USD'])->toMatchArray(['agreements' => 2, 'budget' => 3000.0, 'scheduled' => 750.0, 'received' => 390.0, 'unscheduled' => 2250.0, 'outstanding' => 2600.0])
        ->and($php->all())->toEqual($sql->all());

    $budgetsOnly = AgreementMoney::totals(DonorProposal::query()->get());

    expect(collect($budgetsOnly)->firstWhere('currency', 'USD')['received'])->toBe(0.0);
});

test('owners get one line per currency, with or without the tranche figures', function () {
    $donor = Donor::factory()->create();
    moneyAgreement(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'budget' => '500.00', 'currency' => Currency::Eur]);

    $lines = AgreementMoney::perOwner('donor_id')->where('donor_proposal.donor_id', $donor->id)->orderBy('currency')->get();
    $plain = AgreementMoney::perOwner('donor_id', withTransfers: false)->where('donor_proposal.donor_id', $donor->id)->orderBy('currency')->get();

    expect($lines->pluck('currency')->all())->toBe(['EUR', 'USD'])
        ->and((float) $lines[1]->o_committed)->toBe(1000.0)
        ->and((float) $lines[1]->o_received)->toBe(390.0)
        ->and((float) $lines[1]->o_outstanding)->toBe(600.0)
        ->and((int) $lines[1]->o_overdue_count)->toBe(1)
        ->and($lines[1]->o_last_received)->toBe('2026-05-20')
        ->and((float) $lines[0]->o_outstanding)->toBe(500.0)
        ->and((float) $plain[1]->o_received)->toBe(0.0);

    expect(fn () => AgreementMoney::perOwner('budget'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AgreementMoney::ownerTotalsSelect('x; drop'))->toThrow(InvalidArgumentException::class);
});

test('the column list follows the permission, the year and the mode', function () {
    $keys = fn (array $columns): array => array_column($columns, 'key');

    expect($keys(AgreementMoney::columns()))->toBe(['currency', 'budget', 'scheduled', 'received', 'percent', 'in_transit', 'overdue', 'unscheduled', 'outstanding', 'next_due', 'tranches', 'reports'])
        ->and($keys(AgreementMoney::columns(year: 2026, withFailed: true)))->toBe(['currency', 'budget', 'scheduled', 'received', 'percent', 'due_in_year', 'received_in_year', 'in_transit', 'overdue', 'failed', 'unscheduled', 'outstanding', 'next_due', 'tranches', 'reports'])
        ->and($keys(AgreementMoney::columns(year: 2026, yearOnly: true, withReports: false)))->toBe(['currency', 'budget', 'scheduled', 'received', 'percent', 'in_transit', 'overdue', 'next_due', 'tranches'])
        ->and($keys(AgreementMoney::columns(withTransfers: false)))->toBe(['currency', 'budget', 'reports'])
        ->and(AgreementMoney::columns(year: 2026, yearOnly: true)[1]['label'])->toBe('Budget (whole agreement)');
});
