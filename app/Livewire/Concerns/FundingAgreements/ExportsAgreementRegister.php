<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV export of the funding agreements register.
 *
 * Used by funding-agreements/index only. It reads registerQuery() from
 * ReadsAgreementRegister and selectedYear() and applyRegisterSort() from
 * FiltersFinanceRegister.
 */
trait ExportsAgreementRegister
{
    /**
     * Every agreement the filters match, in the table's order, as a CSV file.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');

        $year = $this->selectedYear();

        /** @var LazyCollection<int, DonorProposal&object{money_scheduled: string, money_received: string, money_in_transit: string, money_overdue: string, money_failed: string, money_unscheduled: string, money_outstanding: string, money_next_due: ?string, money_tranches: int, money_due_in_year: string, money_received_in_year: string, overdue_reports_count: int, next_report_due: ?string}> $agreements */
        $agreements = $this->applyRegisterSort($this->registerQuery())
            ->addSelect(['donors.name as donor_name', 'proposals.name as proposal_name', 'proposals.status as proposal_status'])
            ->cursor();

        $headings = [
            __('funding_agreements.donor'),
            __('common.proposal'),
            __('finance_tables.proposal_status'),
            __('finance_tables.reference'),
            __('common.currency'),
            __('finance_tables.budget'),
            __('finance_tables.scheduled'),
            __('finance_tables.received'),
            __('finance_tables.percent_received'),
            __('finance_tables.in_transit'),
            __('finance_tables.overdue'),
            __('finance_tables.failed'),
            __('finance_tables.not_scheduled'),
            __('finance_tables.outstanding'),
            __('finance_tables.next_due'),
            __('finance_tables.tranches'),
            __('finance_tables.reports_overdue'),
            __('finance_tables.next_report_due'),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($agreements, $year) {
            foreach ($agreements as $agreement) {
                yield [
                    (string) $agreement->getAttribute('donor_name'),
                    (string) $agreement->getAttribute('proposal_name'),
                    ProposalStatus::from((string) $agreement->getAttribute('proposal_status'))->label(),
                    $agreement->external_project_number,
                    $agreement->currency->value,
                    CsvExport::amount($agreement->budget),
                    CsvExport::amount($agreement->money_scheduled),
                    CsvExport::amount($agreement->money_received),
                    DonorProposal::percentLabel((float) $agreement->money_received, (float) $agreement->budget),
                    CsvExport::amount($agreement->money_in_transit),
                    CsvExport::amount($agreement->money_overdue),
                    CsvExport::amount($agreement->money_failed),
                    CsvExport::amount($agreement->money_unscheduled),
                    CsvExport::amount($agreement->money_outstanding),
                    $agreement->money_next_due,
                    (int) $agreement->money_tranches,
                    (int) $agreement->overdue_reports_count,
                    $agreement->next_report_due,
                    ...($year === null ? [] : [
                        CsvExport::amount($agreement->money_due_in_year),
                        CsvExport::amount($agreement->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('funding-agreements-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
}
