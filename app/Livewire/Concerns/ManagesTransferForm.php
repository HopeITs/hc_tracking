<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Transfers\DeleteTransfer;
use App\Actions\Transfers\SaveTransfer;
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The payment tranche form, shared by the transfer directory and the proposal
 * page so a tranche is scheduled and received by one set of rules.
 *
 * The using component renders resources/views/components/funding/transfer-modal
 * and decides what happens after a save through transferSaved(). A page that
 * lives inside one proposal limits the form to that proposal's agreements
 * through transferProposalScope().
 */
trait ManagesTransferForm
{
    /** payment/received/net columns are DECIMAL(14,2). */
    public const MAX_AMOUNT = 999999999999.99;

    /** transfers_tracking.fees is DECIMAL(10,2). */
    public const MAX_FEES = 99999999.99;

    /** transfers_tracking.exchange_rate is DECIMAL(12,6). */
    public const MAX_EXCHANGE_RATE = 999999.999999;

    public bool $showTransferModal = false;

    public bool $confirmingTransferDelete = false;

    #[Locked]
    public ?int $editingTransferId = null;

    public string $transferAgreementId = '';

    public string $transferAccountId = '';

    public string $paymentAmount = '';

    public string $transferredCurrency = 'USD';

    public string $scheduledDate = '';

    public string $transferDate = '';

    public string $fees = '0';

    public string $feesCurrency = 'USD';

    public string $amountReceived = '';

    public string $exchangeRate = '1.000000';

    public string $netAmountReceived = '';

    public string $receivedCurrency = 'USD';

    public string $receiveDate = '';

    public string $transferArStatus = 'pending';

    /**
     * Called after a tranche is recorded or edited.
     */
    abstract protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void;

    /**
     * Called after a tranche is deleted.
     */
    abstract protected function transferDeleted(int $transferId): void;

    /**
     * The proposal whose agreements this page may schedule against, or null
     * when any agreement is allowed.
     */
    protected function transferProposalScope(): ?int
    {
        return null;
    }

    /**
     * Whether the viewer may schedule or edit a tranche. The agreement and
     * account pick-lists carry budgets and bank account names, and the modal
     * flag is client-settable, so they are only ever built for these viewers.
     */
    protected function canUseTransferForm(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('transfers.create') || $user->can('transfers.update'));
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accountOptions(): Collection
    {
        if (! $this->canUseTransferForm()) {
            return new Collection;
        }

        return Account::query()->select(['id', 'account_name', 'is_active'])->orderBy('account_name')->orderBy('id')->get();
    }

    /** @return Collection<int, DonorProposal> */
    #[Computed]
    public function agreementOptions(): Collection
    {
        if (! $this->canUseTransferForm()) {
            return new Collection;
        }

        return $this->scopedAgreements()
            ->select(['id', 'donor_id', 'proposal_id', 'external_project_number', 'currency', 'budget'])
            ->with(['donor:id,name', 'proposal:id,name,internal_project_number'])
            ->orderBy('id')
            ->get();
    }

    /** @return Builder<DonorProposal> */
    protected function scopedAgreements(): Builder
    {
        return DonorProposal::query()
            ->when($this->transferProposalScope() !== null, fn (Builder $query) => $query->where('proposal_id', $this->transferProposalScope()));
    }

    /**
     * Open a new tranche, optionally against one agreement.
     *
     * Every currency starts at the agreement's own: most tranches are sent,
     * charged and received in the currency the budget was signed in.
     */
    public function openTransferCreate(?int $agreementId = null): void
    {
        Gate::authorize('transfers.create');

        $this->resetTransferForm();

        // A page pinned to one proposal with a single agreement has only one
        // possible answer, so it is chosen rather than asked for.
        if ($agreementId === null && $this->transferProposalScope() !== null) {
            $onlyAgreement = $this->scopedAgreements()->limit(2)->pluck('id');
            $agreementId = $onlyAgreement->count() === 1 ? $onlyAgreement->first() : null;
        }

        if ($agreementId !== null) {
            $agreement = $this->scopedAgreements()->select(['id', 'currency'])->findOrFail($agreementId);

            $this->transferAgreementId = (string) $agreement->id;
            $this->useAgreementCurrency($agreement);
        }

        $this->showTransferModal = true;
    }

    /**
     * Picking an agreement for a new tranche sets every currency to the
     * agreement's own, as opening the form from that agreement would. An
     * existing tranche keeps the currencies it was recorded in.
     */
    public function updatedTransferAgreementId(): void
    {
        if ($this->editingTransferId !== null || $this->transferAgreementId === '') {
            return;
        }

        $agreement = $this->scopedAgreements()->select(['id', 'currency'])->find((int) $this->transferAgreementId);

        if ($agreement !== null) {
            $this->useAgreementCurrency($agreement);
        }
    }

    private function useAgreementCurrency(DonorProposal $agreement): void
    {
        $this->transferredCurrency = $agreement->currency->value;
        $this->feesCurrency = $agreement->currency->value;
        $this->receivedCurrency = $agreement->currency->value;
    }

    public function openTransferEdit(int $transferId): void
    {
        Gate::authorize('transfers.update');

        $transfer = $this->scopedTransfer($transferId);

        $this->resetValidation();
        $this->confirmingTransferDelete = false;
        $this->editingTransferId = $transfer->id;
        $this->transferAgreementId = (string) $transfer->donor_proposal_id;
        $this->transferAccountId = (string) ($transfer->account_id ?? '');
        $this->paymentAmount = (string) $transfer->payment_amount;
        $this->transferredCurrency = $transfer->amount_transfered_currency->value;
        $this->scheduledDate = $transfer->scheduled_date?->toDateString() ?? '';
        $this->transferDate = $transfer->transfer_date?->toDateString() ?? '';
        $this->fees = (string) $transfer->fees;
        $this->feesCurrency = $transfer->fees_currency->value;
        $this->amountReceived = (string) ($transfer->amount_received ?? '');
        $this->exchangeRate = (string) $transfer->exchange_rate;
        $this->netAmountReceived = (string) ($transfer->net_amount_received ?? '');
        $this->receivedCurrency = $transfer->amount_received_currency->value;
        $this->receiveDate = $transfer->receive_date?->toDateString() ?? '';
        $this->transferArStatus = $transfer->ar_status->value;
        $this->showTransferModal = true;
    }

    public function saveTransfer(): void
    {
        Gate::authorize($this->editingTransferId === null ? 'transfers.create' : 'transfers.update');

        $existing = $this->editingTransferId === null ? null : $this->scopedTransfer($this->editingTransferId);

        $validated = $this->validate([
            'transferAgreementId' => [
                'required',
                'integer',
                Rule::exists(DonorProposal::class, 'id')->when(
                    $this->transferProposalScope() !== null,
                    fn ($rule) => $rule->where('proposal_id', $this->transferProposalScope()),
                ),
            ],
            'transferAccountId' => [
                'nullable',
                'integer',
                Rule::exists(Account::class, 'id'),
                // is_active decides whether an account can receive money. A
                // tranche already filed against an account that has since
                // closed may keep it; nothing new may be pointed at one.
                function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
                    if ($value === null || $value === '' || (int) $value === $existing?->account_id) {
                        return;
                    }

                    if (Account::query()->whereKey((int) $value)->active()->doesntExist()) {
                        $fail(__('transfers.that_account_is_closed_choose_an_active_one'));
                    }
                },
            ],
            'paymentAmount' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'transferredCurrency' => ['required', new Enum(Currency::class)],
            'scheduledDate' => ['nullable', 'date'],
            // Money cannot have left the donor, or landed, on a day yet to come.
            'transferDate' => ['nullable', 'date', 'before_or_equal:today'],
            'fees' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_FEES],
            'feesCurrency' => ['required', new Enum(Currency::class)],
            'amountReceived' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'exchangeRate' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_EXCHANGE_RATE],
            // Received money only counts once someone records what landed and
            // when; a blank net is never read as the payment amount.
            'netAmountReceived' => ['nullable', 'required_if:transferArStatus,'.ArStatus::Received->value, 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT],
            'receivedCurrency' => ['required', new Enum(Currency::class)],
            // A landing date on a tranche still pending contradicts it: the
            // money has either arrived (received) or it has not.
            'receiveDate' => [
                'nullable',
                'required_if:transferArStatus,'.ArStatus::Received->value,
                'prohibited_if:transferArStatus,'.ArStatus::Pending->value,
                'date',
                'before_or_equal:today',
                ...($this->transferDate !== '' ? ['after_or_equal:transferDate'] : []),
            ],
            'transferArStatus' => ['required', new Enum(ArStatus::class)],
        ], [
            'paymentAmount.max' => __('transfers.an_amount_is_too_large'),
            'amountReceived.max' => __('transfers.an_amount_is_too_large'),
            'netAmountReceived.max' => __('transfers.an_amount_is_too_large'),
            'fees.max' => __('transfers.an_amount_is_too_large'),
            'exchangeRate.max' => __('transfers.an_amount_is_too_large'),
            'exchangeRate.gt' => __('transfers.an_exchange_rate_must_be_above_zero'),
            'transferDate.before_or_equal' => __('transfers.money_cannot_be_sent_in_the_future'),
            'receiveDate.before_or_equal' => __('transfers.money_cannot_land_in_the_future'),
            'receiveDate.after_or_equal' => __('transfers.money_cannot_land_before_it_was_sent'),
            'receiveDate.prohibited_if' => __('transfers.a_pending_tranche_has_no_landing_date'),
            'transferAgreementId.required' => __('transfers.select_the_funding_agreement_this_tranche_belongs_to'),
            'paymentAmount.required' => __('transfers.enter_the_payment_amount'),
            'paymentAmount.min' => __('transfers.a_payment_amount_cannot_be_negative'),
            'fees.min' => __('transfers.fees_cannot_be_negative'),
            'exchangeRate.min' => __('transfers.an_exchange_rate_cannot_be_negative'),
            'netAmountReceived.required_if' => __('transfers.enter_the_net_amount_that_landed'),
            'receiveDate.required_if' => __('transfers.enter_the_date_the_money_landed'),
        ]);

        $transfer = app(SaveTransfer::class)->handle($existing, $validated);

        $isCreate = $transfer->wasRecentlyCreated;

        $this->showTransferModal = false;
        $this->resetTransferForm();
        $this->transferSaved($transfer, $isCreate);

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('transfers.transfer_recorded') : __('transfers.transfer_updated'),
        );
    }

    /**
     * Ask for confirmation inside the open edit form.
     */
    public function confirmTransferDelete(): void
    {
        Gate::authorize('transfers.delete');

        $this->confirmingTransferDelete = $this->editingTransferId !== null;
    }

    public function cancelTransferDelete(): void
    {
        $this->confirmingTransferDelete = false;
    }

    /**
     * Delete the tranche being edited: a mistaken or duplicate entry.
     */
    public function deleteTransfer(): void
    {
        Gate::authorize('transfers.delete');

        if ($this->editingTransferId === null) {
            return;
        }

        $transfer = $this->scopedTransfer($this->editingTransferId);
        app(DeleteTransfer::class)->handle($transfer);

        $this->showTransferModal = false;
        $this->resetTransferForm();
        $this->transferDeleted($transfer->id);

        Flux::toast(variant: 'success', text: __('transfers.transfer_deleted'));
    }

    public function closeTransferModal(): void
    {
        $this->showTransferModal = false;
        $this->resetTransferForm();
    }

    /**
     * A tranche by id, re-scoped to the agreements this page may touch.
     */
    protected function scopedTransfer(int $transferId): TransfersTracking
    {
        return TransfersTracking::query()
            ->whereIn('donor_proposal_id', $this->scopedAgreements()->select('id'))
            ->findOrFail($transferId);
    }

    protected function resetTransferForm(): void
    {
        $this->resetValidation();
        $this->editingTransferId = null;
        $this->confirmingTransferDelete = false;
        $this->transferAgreementId = '';
        $this->transferAccountId = '';
        $this->paymentAmount = '';
        $this->transferredCurrency = 'USD';
        $this->scheduledDate = '';
        $this->transferDate = '';
        $this->fees = '0';
        $this->feesCurrency = 'USD';
        $this->amountReceived = '';
        $this->exchangeRate = '1.000000';
        $this->netAmountReceived = '';
        $this->receivedCurrency = 'USD';
        $this->receiveDate = '';
        $this->transferArStatus = 'pending';
    }
}
