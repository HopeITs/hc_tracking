<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The register's CSV download.
 *
 * Used by proposals/index only. The page uses ChecksFinanceVisibility and
 * FiltersFinanceRegister (selectedYear, applyRegisterSort); selectLine() and
 * joinedQuery() come from ReadsProposalRegister.
 */
trait ExportsProposalRegister
{
    /**
     * Every line the filters match, in the table's order, as a CSV file. The
     * file holds the columns the viewer sees on screen and no others. The
     * donors of a line are named by one grouped subquery, so the rows can be
     * read lazily.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('proposals.view');

        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;

        $query = $this->selectLine($this->joinedQuery());

        if ($canAgreements) {
            $query->selectRaw("(select group_concat(distinct donors.name order by donors.name separator ', ')
                from donor_proposal as funding
                inner join donors on donors.id = funding.donor_id
                where funding.proposal_id = proposals.id and funding.currency = money.currency) as donor_names");
        }

        /** @var LazyCollection<int, Proposal&object{money_currency: ?string, money_agreements: int, money_committed: string, money_received: string, money_outstanding: string, money_overdue: string, money_next_due: ?string, money_due_in_year: string, money_received_in_year: string}> $proposals */
        $proposals = $this->applyRegisterSort($query)->cursor();

        $headings = [
            __('common.proposal'),
            __('common.status'),
            __('proposals.phase'),
            __('proposals.planned_start'),
            __('proposals.planned_end'),
            __('common.projects'),
            ...($canAgreements ? [__('common.donors'), __('finance_tables.agreements'), __('common.currency'), __('common.committed')] : []),
            ...($canTransfers ? [
                __('finance_tables.received'),
                __('finance_tables.percent_received'),
                __('finance_tables.outstanding'),
                __('finance_tables.overdue'),
                __('finance_tables.next_due'),
            ] : []),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($proposals, $canAgreements, $canTransfers, $year) {
            foreach ($proposals as $proposal) {
                $hasMoney = $canAgreements && $proposal->money_currency !== null;

                yield [
                    $proposal->name,
                    $proposal->status->label(),
                    $proposal->phase->label(),
                    $proposal->planned_date_begin,
                    $proposal->planned_date_end,
                    (int) $proposal->projects_count,
                    ...($canAgreements ? [
                        $proposal->getAttribute('donor_names'),
                        (int) $proposal->money_agreements,
                        $proposal->money_currency,
                        $hasMoney ? CsvExport::amount($proposal->money_committed) : null,
                    ] : []),
                    ...($canTransfers ? [
                        $hasMoney ? CsvExport::amount($proposal->money_received) : null,
                        $hasMoney ? DonorProposal::percentLabel((float) $proposal->money_received, (float) $proposal->money_committed) : null,
                        $hasMoney ? CsvExport::amount($proposal->money_outstanding) : null,
                        $hasMoney ? CsvExport::amount($proposal->money_overdue) : null,
                        $proposal->money_next_due,
                    ] : []),
                    ...($year === null ? [] : [
                        CsvExport::amount($proposal->money_due_in_year),
                        CsvExport::amount($proposal->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('proposals-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
}
