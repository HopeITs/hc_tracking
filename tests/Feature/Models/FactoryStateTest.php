<?php

use App\Enums\ArStatus;
use App\Models\Account;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Strategy;
use App\Models\TransfersTracking;
use App\Models\User;

test('a user factory can assign an arbitrary role name', function (string $roleName) {
    $user = User::factory()->role($roleName)->create()->fresh();

    expect($user->roles->first()?->name)->toBe($roleName);
})->with([
    'Financial', 'Media Team', 'MEAL', 'Public Relations',
    'Public Relations Manager', 'Managers', 'Super Admin', 'General Manager',
]);

test('a proposal end date follows an overridden start date', function () {
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2035-04-15'])->fresh();

    expect($proposal->planned_date_end->toDateString())->toBe('2036-04-15')
        ->and($proposal->actual_date_begin->toDateString())->toBe('2035-04-15');
});

test('a strategy spans five years from an overridden start year', function () {
    $strategy = Strategy::factory()->create(['start_year' => 2035])->fresh();

    expect($strategy->end_year)->toBe(2039);
});

test('factory callers can still supply explicit end dates and years', function () {
    $proposal = Proposal::factory()->create([
        'planned_date_begin' => '2035-04-15',
        'planned_date_end' => '2035-06-30',
    ])->fresh();
    $strategy = Strategy::factory()->create(['start_year' => 2035, 'end_year' => 2040])->fresh();

    expect($proposal->planned_date_end->toDateString())->toBe('2035-06-30');
    expect($strategy->end_year)->toBe(2040);
});

/**
 * Factory states derive columns from other columns. A plain state closure sees
 * only the definition, not the array passed to create(), so a derived value
 * would silently be computed from a throwaway random figure. These states use
 * per-attribute closures instead, which Laravel resolves after everything is
 * merged. Each test below overrides the column the state derives from.
 */
test('a received tranche derives its fee and net from the payment actually passed in', function () {
    $tranche = TransfersTracking::factory()->received()->create([
        'payment_amount' => '100000.00',
        'amount_received' => '100000.00',
    ])->fresh();

    expect($tranche->fees)->toBe('1000.00')
        ->and($tranche->net_amount_received)->toBe('99000.00')
        ->and($tranche->ar_status)->toBe(ArStatus::Received);
});

test('a received tranche never nets more than it received', function () {
    $tranche = TransfersTracking::factory()->received()->create([
        'payment_amount' => '250000.00',
        'amount_received' => '250000.00',
    ])->fresh();

    expect((float) $tranche->net_amount_received)
        ->toBeLessThanOrEqual((float) $tranche->amount_received);
});

test('a received tranche takes its transfer dates from the schedule passed in', function () {
    $tranche = TransfersTracking::factory()->received()->create([
        'scheduled_date' => '2026-04-01',
    ])->fresh();

    expect($tranche->transfer_date->toDateString())->toBe('2026-04-01')
        ->and($tranche->receive_date->toDateString())->toBe('2026-04-01');
});

test('an approved report takes its submission date from the planned date passed in', function () {
    $report = Report::factory()->approved()->create(['planned_date' => '2026-03-31'])->fresh();

    expect($report->submit_date->toDateString())->toBe('2026-03-31');
});

test('a narrative report bounds its period from the planned date passed in', function () {
    $report = Report::factory()->withNarrative()->create(['planned_date' => '2026-03-31'])->fresh();

    expect($report->period_end->toDateString())->toBe('2026-03-31')
        ->and($report->period_start->toDateString())->toBe('2025-12-31');
});

test('a reached milestone takes its actual date from the planned date passed in', function () {
    $milestone = Milestone::factory()->reached()->create(['planned_date' => '2026-06-30'])->fresh();

    expect($milestone->actual_date->toDateString())->toBe('2026-06-30');
});

test('a running proposal starts on the planned begin date passed in', function () {
    $proposal = Proposal::factory()->running()->create(['planned_date_begin' => '2026-01-15'])->fresh();

    expect($proposal->actual_date_begin->toDateString())->toBe('2026-01-15');
});

test('a closed account closes no earlier than the opening date passed in', function () {
    $account = Account::factory()->closed()->create(['opened_at' => '2025-01-01'])->fresh();

    expect($account->closed_at->toDateString())->toBeGreaterThanOrEqual('2025-01-01')
        ->and($account->is_active)->toBeFalse();
});
