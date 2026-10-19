<?php

use App\Livewire\Concerns\BuildsPortfolioGantt;
use App\Livewire\Concerns\FundingAgreements\ManagesDonorReports;
use App\Livewire\Concerns\FundingAgreements\ReadsAgreementAttention;
use App\Livewire\Concerns\FundingAgreements\ReadsAgreementConnections;
use App\Livewire\Concerns\FundingAgreements\ReadsAgreementMoney;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\SwitchesFundingView;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\CsvExport;
use App\Support\Tranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.funding_agreement')] class extends Component {
    use BuildsPortfolioGantt;
    use ManagesAgreementForm;
    use SwitchesFundingView;
    use ManagesDonorReports;
    use ReadsAgreementAttention;
    use ReadsAgreementConnections;
    use ReadsAgreementMoney;
    use WithFileUploads;
    use ManagesTransferForm {
        saveTransfer as private saveTransferForAgreement;
    }

    #[Locked]
    public int $agreementId;

    public function mount(DonorProposal $donorProposal): void
    {
        $this->agreementId = $donorProposal->id;
    }

    /**
     * The agreement on one Gantt: the proposal that won it, the delivery it
     * funds, and every tranche and donor report it owes.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function ganttPayload(): array
    {
        return $this->agreementGanttPayload($this->agreement);
    }

    #[Computed]
    public function agreement(): DonorProposal
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number', 'created_at', 'updated_at'])
            ->with([
                'donor:id,name,type,contact_person,email,phone,country,last_contacted_on,next_step,next_step_due_on',
                'proposal:id,name,internal_project_number,status,planned_date_begin,planned_date_end,actual_date_begin,actual_date_end',
            ])
            ->findOrFail($this->agreementId);
    }

    /** @return Collection<int, TransfersTracking> */
    #[Computed]
    public function transfers(): Collection
    {
        return TransfersTracking::query()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->where('donor_proposal_id', $this->agreementId)
            // account_id is nullable: a tranche may be scheduled before an
            // account is assigned.
            ->with('account:id,account_name,is_active')
            ->orderByRaw('scheduled_date is null, scheduled_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every tranche of this agreement as a CSV file, in schedule order.
     */
    public function exportTranchesCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');

        $agreement = $this->agreement;

        $rows = $this->transfers->map(fn (TransfersTracking $transfer): array => Tranches::csvRow(
            $transfer,
            $agreement->donor->name,
            $agreement->proposal->name,
            $agreement->external_project_number,
            $transfer->account?->account_name,
        ));

        return CsvExport::download('agreement-tranches-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }

    /**
     * Pinned to this agreement's proposal, so an edit cannot move the
     * agreement onto another grant.
     */
    protected function agreementProposalScope(): ?int
    {
        return $this->agreement->proposal_id;
    }

    /**
     * Tranches recorded or edited here belong to this agreement only.
     *
     * @return Builder<DonorProposal>
     */
    protected function scopedAgreements(): Builder
    {
        return DonorProposal::query()->whereKey($this->agreementId);
    }

    /**
     * Every tranche saved here belongs to this agreement, whatever agreement
     * id the payload carries.
     */
    public function saveTransfer(): void
    {
        $this->transferAgreementId = (string) $this->agreementId;

        $this->saveTransferForAgreement();
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->forgetReadings();
    }

    /**
     * This page shows one agreement, so once it is gone there is nothing left
     * to show: go back to the agreement directory.
     */
    protected function agreementDeleted(int $agreementId): void
    {
        $this->redirectRoute('funding-agreements.index', navigate: true);
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->forgetReadings();
    }

    /**
     * Drop every memoised reading so the next render recomputes from the
     * database. Everything on this page derives from the agreement and its
     * tranches, so a write anywhere clears them all.
     */
    private function forgetReadings(): void
    {
        unset(
            $this->agreement, $this->transfers, $this->money, $this->journeys, $this->arcs,
            $this->term, $this->attention, $this->projects, $this->relatedAgreements, $this->accounts, $this->paperwork, $this->reports, $this->reportResponsibles,
        );
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $amount = fn (float $value): string => number_format($value, 2);
    $agreement = $this->agreement;
    $donor = $agreement->donor;
    $proposal = $agreement->proposal;
    $currency = $agreement->currency->value;
    $money = $this->money;
    $term = $this->term;
    $journeys = $this->journeys;
    $coFunders = $this->relatedAgreements->where('proposal_id', $agreement->proposal_id)->values();
    $donorAgreements = $this->relatedAgreements->where('donor_id', $agreement->donor_id)->where('proposal_id', '!=', $agreement->proposal_id)->values();
    $arcStroke = [
        'received' => 'stroke-funding',
        'transit' => 'stroke-status-warn',
        'upcoming' => 'dial-upcoming',
        'late' => 'stroke-primary',
        'failed' => 'dial-failed',
        'unrecorded' => 'dial-unrecorded',
    ];
    $dotClass = [
        'received' => 'bg-funding',
        'transit' => 'bg-status-warn',
        'upcoming' => 'money-pending',
        'late' => 'bg-primary dark:bg-status-critical',
        'failed' => 'bg-primary dark:bg-status-critical',
    ];
    $tranchesForDial = collect($journeys)->mapWithKeys(fn (array $journey): array => [
        $journey['transfer']->id => [
            'amount' => $amount((float) $journey['transfer']->payment_amount).' '.$journey['transfer']->amount_transfered_currency->value,
            'label' => __('funding_agreements.tranche_number', ['number' => $journey['number'], 'total' => count($journeys)]),
            'state' => $this->trancheStateLabel($journey['state']),
            'alarm' => in_array($journey['state'], ['late', 'failed'], true),
        ],
    ])->all();
@endphp

<section
    class="agreement-page @container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    x-data="{ lit: null, tranches: @js($tranchesForDial) }"
>
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.funding_agreements'), 'url' => route('funding-agreements.index')],
            ['label' => $donor->name.' · '.$proposal->name],
        ]"
        :title="$donor->name.' · '.$proposal->name"
        :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'donor_proposal', $this->agreementId)"
        title-class="text-3xl! font-bold! leading-tight! tracking-tight! text-balance @3xl/detail:text-[2.5rem]!"
    >
        <x-slot:meta>
            <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" label class="{{ $inkText }}" />
            <span aria-hidden="true">·</span>
            <x-ui.project-number :number="$agreement->external_project_number" kind="external" label class="{{ $inkText }}" />
            <span aria-hidden="true">·</span>
            <span>{{ __('funding_agreements.signed_in_currency', ['currency' => $currency]) }}</span>
            @if ($agreement->updated_at !== null)
                <span aria-hidden="true">·</span>
                <span>{{ __('common.updated_date', ['date' => $agreement->updated_at->translatedFormat(__('formats.date'))]) }}</span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2 @3xl/detail:shrink-0 @3xl/detail:flex-nowrap">
                <x-ui.enum-badge :case="$proposal->status" />
                <x-ui.view-toggle
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
                @can('funding-agreements.update')
                <x-ui.button size="sm" icon="pencil-square" class="min-h-10 touch-manipulation" wire:click="openAgreementEdit({{ $agreement->id }})" data-test="edit-agreement">{{ __('funding_agreements.edit_agreement') }}</x-ui.button>
                @endcan
                @can('transfers.create')
                <x-ui.button tone="primary" size="sm" icon="calendar-days" class="min-h-10 touch-manipulation" wire:click="openTransferCreate({{ $agreement->id }})" data-test="schedule-tranche">{{ __('funding_agreements.schedule_tranche') }}</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @if ($this->view === 'overview')
        @include('pages.funding-agreements._show-overview')
    @else
        @php
            $totalLines = [[
                'currency' => $currency,
                'caption' => trans_choice('common.count_tranche', count($journeys), ['count' => count($journeys)]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => __('finance_tables.budget'), 'value' => $money['budget']],
                    // Every tranche sent in this currency, whatever became of it: failed money was scheduled too.
                    ['key' => 'scheduled', 'label' => __('finance_tables.scheduled'), 'value' => $money['scheduled'] + $money['failed']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $money['landed'], 'tone' => 'good', 'note' => $money['percentLabel'].'%'],
                    ['key' => 'lost', 'label' => __('funding_agreements.fees_and_exchange'), 'value' => $money['lost'], 'prefix' => $money['lost'] > 0 ? '−' : ''],
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $money['transit'], 'tone' => $money['transit'] > 0 ? 'warn' : null],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $money['late'], 'tone' => $money['late'] > 0 ? 'critical' : null],
                    $money['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $money['failed'], 'tone' => 'critical'] : null,
                    ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $money['unscheduled'], 'tone' => 'muted'],
                    ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $money['stillToCome']],
                ])),
                'notes' => array_values(array_filter([
                    ...collect($money['foreignLandings'])->map(fn (float $foreignTotal, string $foreignCode): string => __('funding_agreements.also_landed_in', ['amount' => $amount($foreignTotal), 'currency' => $foreignCode]))->values()->all(),
                    $money['foreignCount'] > 0 ? trans_choice('funding_agreements.count_tranches_sent_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount']]) : null,
                ])),
            ]];
        @endphp

        <div class="pt-8">
            <x-funding.totals :lines="$totalLines" />
        </div>

        @include('pages.funding-agreements._show-attention')

        @include('pages.funding-agreements._show-tranches')

        @include('pages.funding-agreements._show-reports')

        <div class="mt-10 border-t pt-8 {{ $hairline }}">
            @include('pages.funding-agreements._show-connections', ['connectionsAsColumns' => true])
        </div>
    @endif

    <section aria-labelledby="record-activity-heading" class="grid gap-3 mt-14" data-record-activity>
        <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
        <livewire:activity.timeline :subjects="['donor_proposal' => [$agreementId], 'transfer' => $this->transfers->pluck('id')->all(), 'donor_report' => $this->reports->pluck('id')->all()]" :key="'agreement-activity-'.$agreementId" />
    </section>

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :warning="$showAgreementModal ? $this->agreementStatusWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :donor-name="$showAgreementModal ? $this->agreementDonorName : null"
        :installments="$installments"
        :installments-total="$this->installmentsTotal"
        :installments-differ="$this->installmentsDifferFromBudget"
        :currency-code="$agreementCurrency"
        :locked-proposal-name="$proposal->name"
    />

    @include('pages.funding-agreements._show-report-modal')

    <x-donor-reports.submit-modal :open="$showSubmitModal" :needs-file="$submissionNeedsFile" :file-count="$submissionFileCount" />

    <x-ui.confirm-modal
        wire:model="showReportDeleteModal"
        close="closeReportDeleteModal"
        :title="__('funding_agreements.delete_this_report')"
        :message="__('funding_agreements.the_report_is_removed_from_this_agreement')"
        confirm="deleteReport"
        :confirm-label="__('funding_agreements.delete_report')"
        confirm-test="confirm-delete-report"
    />

    <x-funding.transfer-modal
        :open="$showTransferModal"
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />
</section>
