<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Donors;

use App\Models\Donor;
use App\Models\DonorProposal;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV export of the donors register.
 *
 * Used by donors/index only. It reads joinedQuery() from ReadsDonorRegister,
 * applyRegisterSort() and selectedYear() from FiltersFinanceRegister, and
 * canViewAgreements() and canViewTransfers() from ChecksFinanceVisibility.
 */
trait ExportsDonorRegister
{
    /**
     * Every line the filters match, in the table's order, as a CSV file. The
     * file holds the columns the viewer sees on screen and no others.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('donors.view');

        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;

        $query = $this->joinedQuery()->select('donors.*');

        if ($canAgreements) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        /** @var LazyCollection<int, Donor&object{money_currency: ?string, money_agreements: int, money_committed: string, money_received: string, money_outstanding: string, money_overdue: string, money_overdue_count: int, money_next_due: ?string, money_last_received: ?string, money_due_in_year: string, money_received_in_year: string}> $donors */
        $donors = $this->applyRegisterSort($query)->cursor();

        $headings = [
            __('donors.donor'),
            __('common.type'),
            __('donors.country'),
            __('donors.contact_person'),
            __('common.email'),
            __('donors.phone'),
            __('donors.next_step'),
            __('donors.next_step_due_on'),
            __('donors.last_contacted_on'),
            ...($canAgreements ? [__('finance_tables.agreements'), __('common.currency'), __('common.committed')] : []),
            ...($canTransfers ? [
                __('finance_tables.received'),
                __('finance_tables.percent_received'),
                __('finance_tables.outstanding'),
                __('finance_tables.overdue'),
                __('finance_tables.overdue_transfers'),
                __('donors.next_transfer'),
                __('finance_tables.last_received'),
            ] : []),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($donors, $canAgreements, $canTransfers, $year) {
            foreach ($donors as $donor) {
                yield [
                    $donor->name,
                    $donor->type?->label(),
                    $donor->country,
                    $donor->contact_person,
                    $donor->email,
                    $donor->phone,
                    $donor->next_step,
                    $donor->next_step_due_on,
                    $donor->last_contacted_on,
                    ...($canAgreements ? [
                        (int) $donor->money_agreements,
                        $donor->money_currency,
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_committed),
                    ] : []),
                    ...($canTransfers ? [
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_received),
                        $donor->money_currency === null ? null : DonorProposal::percentLabel((float) $donor->money_received, (float) $donor->money_committed),
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_outstanding),
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_overdue),
                        (int) $donor->money_overdue_count,
                        $donor->money_next_due,
                        $donor->money_last_received,
                    ] : []),
                    ...($year === null ? [] : [
                        CsvExport::amount($donor->money_due_in_year),
                        CsvExport::amount($donor->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('donors-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }
}
