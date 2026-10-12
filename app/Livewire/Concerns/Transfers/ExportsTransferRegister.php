<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Transfers;

use App\Support\CsvExport;
use App\Support\Tranches;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV export of the transfers register.
 *
 * Used by transfers/index only. It reads registerQuery() and groupOrder()
 * from ReadsTransferRegister and applyRegisterSort() from
 * FiltersFinanceRegister.
 */
trait ExportsTransferRegister
{
    /**
     * Every tranche the filters match, in the table's order, as a CSV file.
     *
     * The rows are read lazily and name their donor, proposal and account from
     * the joined columns, so a large export holds one tranche in memory at a
     * time.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('transfers.view');

        $transfers = $this->applyRegisterSort($this->groupOrder($this->registerQuery()))
            ->select([
                'transfers_tracking.*',
                'donors.name as donor_name',
                'proposals.name as proposal_name',
                'donor_proposal.external_project_number as agreement_reference',
                'accounts.account_name as landing_account',
            ])
            ->cursor();

        $rows = (function () use ($transfers) {
            foreach ($transfers as $transfer) {
                yield Tranches::csvRow(
                    $transfer,
                    (string) $transfer->getAttribute('donor_name'),
                    (string) $transfer->getAttribute('proposal_name'),
                    $transfer->getAttribute('agreement_reference'),
                    $transfer->getAttribute('landing_account'),
                );
            }
        })();

        return CsvExport::download('transfers-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }
}
