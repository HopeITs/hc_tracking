<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;
use Illuminate\Support\Facades\Blade;

/**
 * A 1,000 USD agreement with 400 received (390 net), 200 overdue and one
 * overdue donor report, loaded the way the agreement tables load it.
 */
function componentAgreement(): DonorProposal
{
    $agreement = DonorProposal::factory()->create(['budget' => '1000.00', 'currency' => Currency::Usd, 'external_project_number' => 'REF-7']);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '400.00',
        'amount_transfered_currency' => Currency::Usd,
        'net_amount_received' => '390.00',
        'amount_received_currency' => Currency::Usd,
        'scheduled_date' => now()->subMonth()->toDateString(),
        'transfer_date' => now()->subMonth()->toDateString(),
        'receive_date' => now()->subMonth()->toDateString(),
        'ar_status' => ArStatus::Received,
    ]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '200.00',
        'amount_transfered_currency' => Currency::Usd,
        'scheduled_date' => now()->subDays(10)->toDateString(),
        'transfer_date' => null,
        'ar_status' => ArStatus::Pending,
    ]);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => now()->subDays(2)->toDateString()]);

    return DonorProposal::query()
        ->select('donor_proposal.*')
        ->withMoney()
        ->withCount(['reports as overdue_reports_count' => fn ($query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', now()->toDateString())])
        ->withMin(['reports as next_report_due' => fn ($query) => $query->whereNull('submitted_on')->whereDate('due_on', '>=', now()->toDateString())], 'due_on')
        ->findOrFail($agreement->id);
}

test('an agreement row prints every money column exactly', function () {
    $agreement = componentAgreement();
    $columns = AgreementMoney::columns();

    $html = Blade::render(
        '<table><tbody><x-funding.agreement-row :agreement="$agreement" :columns="$columns" /></tbody></table>',
        compact('agreement', 'columns'),
    );

    expect($html)->toContain('data-agreement-row="'.$agreement->id.'"')
        ->and($html)->toContain('1,000.00')
        ->and($html)->toContain('600.00')
        ->and($html)->toContain('390.00')
        ->and($html)->toContain('39%')
        ->and($html)->toContain('200.00')
        ->and($html)->toContain('400.00')
        ->and($html)->toContain('data-report-overdue')
        ->and($html)->toContain('1 donor report overdue');
});

test('the row and the head always print the same number of cells', function (array $arguments) {
    $agreement = componentAgreement();
    $columns = AgreementMoney::columns(...$arguments);

    $head = Blade::render('<table><thead><tr><x-funding.agreement-head :columns="$columns" /></tr></thead></table>', compact('columns'));
    $row = Blade::render('<table><tbody><x-funding.agreement-row :agreement="$agreement" :columns="$columns" /></tbody></table>', compact('agreement', 'columns'));

    expect(substr_count($head, '<th scope='))->toBe(count($columns) + 1)
        ->and(substr_count($row, '<td'))->toBe(count($columns));
})->with([
    'every column' => [['withFailed' => true]],
    'a year beside the lifetime figures' => [['year' => 2026]],
    'one year only' => [['year' => 2026, 'yearOnly' => true]],
    'no transfers permission' => [['withTransfers' => false]],
    'no reports' => [['withReports' => false]],
]);

test('without the transfers columns a row shows the budget and nothing derived from tranches', function () {
    $agreement = componentAgreement();
    $columns = AgreementMoney::columns(withTransfers: false, withReports: false);

    $html = Blade::render('<table><tbody><x-funding.agreement-row :agreement="$agreement" :columns="$columns" /></tbody></table>', compact('agreement', 'columns'));

    expect($html)->toContain('1,000.00')
        ->and($html)->not->toContain('390.00')
        ->and($html)->not->toContain('data-cell="received"');
});
