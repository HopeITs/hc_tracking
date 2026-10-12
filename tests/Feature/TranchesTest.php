<?php

use App\Enums\ArStatus;
use App\Models\TransfersTracking;
use App\Support\Tranches;

beforeEach(fn () => $this->travelTo('2026-06-15 09:00:00'));

test('timing reads overdue, in transit and landed days', function () {
    $tranche = fn (array $values): TransfersTracking => TransfersTracking::factory()->create($values)->fresh();

    expect(Tranches::timing($tranche(['scheduled_date' => '2026-06-10', 'transfer_date' => null])))->toBe('5 days overdue')
        ->and(Tranches::timing($tranche(['scheduled_date' => '2026-06-01', 'transfer_date' => '2026-06-14'])))->toBe('In transit for 1 day')
        ->and(Tranches::timing($tranche(['ar_status' => ArStatus::Received, 'transfer_date' => '2026-04-03', 'receive_date' => '2026-04-08'])))->toBe('Landed in 5 days')
        ->and(Tranches::timing($tranche(['ar_status' => ArStatus::Received, 'transfer_date' => '2026-04-08', 'receive_date' => '2026-04-03'])))->toBe('Landed the same day')
        ->and(Tranches::timing($tranche(['scheduled_date' => '2026-07-01', 'transfer_date' => null])))->toBeNull()
        ->and(Tranches::stateLabel('late'))->toBe('Overdue')
        ->and(Tranches::stateLabel('received', true))->toBe('Net not recorded');
});

test('a csv row holds plain values and hides what has not landed', function () {
    $settled = TransfersTracking::factory()->create(['ar_status' => ArStatus::Received, 'payment_amount' => '1000.00', 'fees' => '10.00', 'amount_received' => '995.00', 'net_amount_received' => '985.00', 'exchange_rate' => '0.925000', 'scheduled_date' => '2026-03-01', 'transfer_date' => '2026-03-02', 'receive_date' => '2026-03-05'])->fresh();
    $pending = TransfersTracking::factory()->create(['payment_amount' => '500.00', 'net_amount_received' => '777.00', 'scheduled_date' => '2026-07-01', 'transfer_date' => null])->fresh();

    $row = Tranches::csvRow($settled, 'ECHO', 'Water', 'WR-9', 'Operations');
    $later = Tranches::csvRow($pending, 'ECHO', 'Water', null, null);

    expect(Tranches::csvHeadings())->toHaveCount(18)
        ->and($row)->toHaveCount(18)
        ->and([$row[0], $row[9], $row[12], $row[13], $row[14], $row[16], $row[17]])->toBe(['Received', '1000.00', '0.925', '995.00', '985.00', '-15.00', 'received'])
        ->and([$later[0], $later[13], $later[14], $later[15], $later[16]])->toBe(['Upcoming', '', '', '', '']);
});
