<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\FundingAgreements\DeleteFundingAgreement;
use App\Actions\FundingAgreements\SaveAgreementInstallments;
use App\Actions\FundingAgreements\SaveFundingAgreement;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The funding agreement form, shared by the agreement directory and the
 * proposal page so an agreement is recorded and edited by one set of rules.
 *
 * The using component renders resources/views/components/funding/agreement-modal
 * and decides what happens after a save through agreementSaved(). A page that
 * lives inside one proposal pins the form to it through agreementProposalScope(),
 * and a page that lives inside one donor pins it through agreementDonorScope(),
 * so a tampered payload cannot file the agreement against another record.
 *
 * @property-read string|null $agreementDeleteBlocker  #[Computed] agreementDeleteBlocker()
 */
trait ManagesAgreementForm
{
    /** donor_proposal.budget is DECIMAL(14,2). */
    public const MAX_BUDGET = 999999999999.99;

    public bool $showAgreementModal = false;

    #[Locked]
    public ?int $editingAgreementId = null;

    public bool $confirmingAgreementDelete = false;

    public string $agreementProposalId = '';

    public string $budget = '';

    public string $agreementCurrency = 'USD';

    public string $externalProjectNumber = '';

    /**
     * The installment rows being edited; the sequence is the row's position.
     *
     * @var list<array{amount: string, scheduled_date: string}>
     */
    public array $installments = [];

    /**
     * The user's explicit yes to a schedule that does not add up to the budget.
     */
    public bool $confirmInstallmentSum = false;

    /**
     * Called after an agreement is recorded or edited.
     */
    abstract protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void;

    /**
     * Called after an agreement is deleted. A page that shows only that
     * agreement leaves; a list refreshes.
     */
    abstract protected function agreementDeleted(int $agreementId): void;

    /**
     * The proposal every agreement on this page must belong to, or null when
     * the page may file against any proposal.
     */
    protected function agreementProposalScope(): ?int
    {
        return null;
    }

    /**
     * The donor every agreement on this page must belong to, or null when the
     * page may file for any donor.
     */
    protected function agreementDonorScope(): ?int
    {
        return null;
    }

    /** @return Collection<int, Donor> */
    #[Computed]
    public function donorOptions(): Collection
    {
        return Donor::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The proposals an agreement can be recorded for: donor-approved, without
     * an agreement yet, inside this page's scope (plus the one being edited).
     * Names and numbers only: no money column is ever selected here.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()
            ->select(['id', 'name', 'internal_project_number', 'status', 'donor_id'])
            ->with('donor:id,name')
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $eligible) => $eligible
                    ->whereIn('status', array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor()))
                    ->whereDoesntHave('donorProposals'));

                if ($this->editingAgreementId !== null) {
                    $query->orWhereHas('donorProposals', fn (Builder $agreement) => $agreement->whereKey($this->editingAgreementId));
                }
            })
            ->when($this->agreementProposalScope() !== null, fn (Builder $query) => $query->whereKey($this->agreementProposalScope()))
            ->when($this->agreementDonorScope() !== null, fn (Builder $query) => $query->where('donor_id', $this->agreementDonorScope()))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * The donor the chosen proposal belongs to, shown read-only: the agreement
     * always takes it from the proposal.
     */
    #[Computed]
    public function agreementDonorName(): ?string
    {
        $proposalId = (int) ($this->agreementProposalScope() ?? $this->agreementProposalId);

        if ($proposalId === 0) {
            return $this->agreementDonorScope() === null ? null : Donor::query()->whereKey($this->agreementDonorScope())->value('name');
        }

        return Proposal::query()->whereKey($proposalId)->first(['id', 'donor_id'])?->donor()->value('name');
    }

    /**
     * The proposal of the agreement being edited, for the read-only field.
     */
    #[Computed]
    public function agreementProposalLabel(): ?string
    {
        if ($this->editingAgreementId === null) {
            return null;
        }

        $proposal = Proposal::query()
            ->whereIn('id', DonorProposal::query()->whereKey($this->editingAgreementId)->select('proposal_id'))
            ->first(['id', 'name', 'internal_project_number']);

        return $proposal === null ? null : $proposal->name.' · '.$proposal->internal_project_number;
    }

    /**
     * A warning when the agreement being edited sits on a proposal that is no
     * longer donor-approved (a legacy agreement, or a status corrected away
     * from approved). The agreement is kept; the mismatch is made visible.
     */
    #[Computed]
    public function agreementStatusWarning(): ?string
    {
        if ($this->editingAgreementId === null) {
            return null;
        }

        $status = DonorProposal::query()->whereKey($this->editingAgreementId)->first(['id', 'proposal_id'])?->proposal()->value('status');

        return $status === null || $status->isApprovedByDonor()
            ? null
            : __('funding_agreements.proposal_status_inconsistent_with_agreement');
    }

    /**
     * What the rows add up to, for the running total beside the budget.
     */
    #[Computed]
    public function installmentsTotal(): string
    {
        return SaveAgreementInstallments::sumOf($this->installments);
    }

    /**
     * Whether a schedule is entered that does not add up to the budget.
     */
    #[Computed]
    public function installmentsDifferFromBudget(): bool
    {
        return $this->installments !== [] && is_numeric($this->budget)
            && SaveAgreementInstallments::sumDiffersFromBudget($this->budget, $this->installments);
    }

    public function addInstallment(): void
    {
        $this->installments[] = ['amount' => '', 'scheduled_date' => ''];
        $this->confirmInstallmentSum = false;
    }

    public function removeInstallment(int $index): void
    {
        unset($this->installments[$index]);
        $this->installments = array_values($this->installments);
        $this->confirmInstallmentSum = false;
    }

    public function updatedInstallments(): void
    {
        $this->confirmInstallmentSum = false;
    }

    public function updatedBudget(): void
    {
        $this->confirmInstallmentSum = false;
    }

    /**
     * Choosing a proposal for a new agreement starts the form from what the
     * proposal asked for (budget and currency), editable. Only users who may
     * record agreements reach this form, so the figures go to nobody else.
     */
    public function updatedAgreementProposalId(): void
    {
        if ($this->editingAgreementId === null) {
            $this->prefillFromProposal();
        }
    }

    protected function prefillFromProposal(): void
    {
        $proposalId = (int) ($this->agreementProposalScope() ?? $this->agreementProposalId);

        if ($proposalId === 0 || ! Gate::allows('funding-agreements.create')) {
            return;
        }

        $proposal = $this->proposalOptions->firstWhere('id', $proposalId)
            ? Proposal::query()->whereKey($proposalId)->first(['id', 'requested_budget', 'requested_currency'])
            : null;

        if ($proposal === null) {
            return;
        }

        $this->budget = $proposal->requested_budget === null ? '' : (string) $proposal->requested_budget;
        $this->agreementCurrency = $proposal->requested_currency?->value ?? 'USD';
        unset($this->agreementDonorName);
    }

    /**
     * Why the agreement being edited cannot be deleted, or null when it can.
     *
     * An agreement that carries tranches or donor reports is history: deleting
     * it would cascade into every record of money that moved under it.
     */
    #[Computed]
    public function agreementDeleteBlocker(): ?string
    {
        if ($this->editingAgreementId === null) {
            return null;
        }

        $agreement = DonorProposal::query()
            ->whereKey($this->editingAgreementId)
            ->withCount(['transfers', 'reports'])
            ->first(['id']);

        return match (true) {
            $agreement === null => null,
            $agreement->transfers_count > 0 => trans_choice('funding_agreements.cannot_delete_agreement_with_tranches', $agreement->transfers_count, ['count' => $agreement->transfers_count]),
            $agreement->reports_count > 0 => trans_choice('funding_agreements.cannot_delete_agreement_with_reports', $agreement->reports_count, ['count' => $agreement->reports_count]),
            default => null,
        };
    }

    /**
     * Whether the agreement being edited already has tranches, so its currency
     * is fixed and the form shows it rather than offering a choice.
     */
    #[Computed]
    public function agreementCurrencyLocked(): bool
    {
        return $this->editingAgreementId !== null
            && DonorProposal::query()->whereKey($this->editingAgreementId)->whereHas('transfers')->exists();
    }

    public function openAgreementCreate(): void
    {
        Gate::authorize('funding-agreements.create');

        $this->resetAgreementForm();
        $this->prefillFromProposal();
        $this->showAgreementModal = true;
    }

    public function openAgreementEdit(int $agreementId): void
    {
        Gate::authorize('funding-agreements.update');

        $agreement = $this->scopedAgreement($agreementId);

        $this->resetValidation();
        $this->confirmingAgreementDelete = false;
        $this->editingAgreementId = $agreement->id;
        $this->agreementProposalId = (string) $agreement->proposal_id;
        $this->budget = (string) $agreement->budget;
        $this->agreementCurrency = $agreement->currency->value;
        $this->externalProjectNumber = $agreement->external_project_number ?? '';
        $this->installments = $agreement->installments()
            ->get(['id', 'funding_agreement_id', 'sequence', 'amount', 'scheduled_date'])
            ->map(fn ($installment): array => ['amount' => (string) $installment->amount, 'scheduled_date' => $installment->scheduled_date->toDateString()])
            ->all();
        $this->confirmInstallmentSum = false;
        unset($this->agreementDonorName, $this->agreementStatusWarning, $this->agreementProposalLabel, $this->proposalOptions);
        $this->showAgreementModal = true;
    }

    public function saveAgreement(): void
    {
        Gate::authorize($this->editingAgreementId === null ? 'funding-agreements.create' : 'funding-agreements.update');

        $this->externalProjectNumber = trim($this->externalProjectNumber);

        if ($this->agreementProposalScope() !== null) {
            $this->agreementProposalId = (string) $this->agreementProposalScope();
        }

        $existing = $this->editingAgreementId === null ? null : $this->scopedAgreement($this->editingAgreementId);

        // An edit never moves the agreement: its proposal (and so its donor) is fixed.
        if ($existing !== null) {
            $this->agreementProposalId = (string) $existing->proposal_id;
        }

        $validated = $this->validate([
            'agreementProposalId' => [
                'required',
                'integer',
                Rule::exists(Proposal::class, 'id')->when(
                    $existing === null && $this->agreementDonorScope() !== null,
                    fn ($rule) => $rule->where('donor_id', $this->agreementDonorScope()),
                ),
            ],
            'budget' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_BUDGET],
            'agreementCurrency' => [
                'required',
                new Enum(Currency::class),
                // Every tranche is measured against the budget in its own
                // currency, so the currency is fixed once money is scheduled.
                function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
                    if ($existing !== null && $existing->currency->value !== $value && $existing->transfers()->exists()) {
                        $fail(__('funding_agreements.currency_is_fixed_once_tranches_exist'));
                    }
                },
            ],
            'externalProjectNumber' => ['nullable', 'string', 'max:50'],
            'installments' => ['array'],
        ], [
            'agreementProposalId.required' => __('funding_agreements.select_the_proposal_being_funded'),
            'agreementProposalId.exists' => __('funding_agreements.select_the_proposal_being_funded'),
            'budget.required' => __('funding_agreements.enter_the_agreed_budget'),
            'budget.min' => __('funding_agreements.a_budget_cannot_be_negative'),
            'budget.max' => __('funding_agreements.a_budget_is_too_large'),
            'agreementCurrency.required' => __('funding_agreements.select_the_currency_this_budget_is_stated_in'),
        ]);

        $rows = $this->installmentPayload($validated['agreementCurrency']);

        // A schedule that does not add up is allowed, once the user says so.
        if ($rows !== [] && ! $this->confirmInstallmentSum && SaveAgreementInstallments::sumDiffersFromBudget($validated['budget'], $rows)) {
            $this->addError('confirmInstallmentSum', __('funding_agreements.installments_sum_mismatch', ['sum' => number_format((float) SaveAgreementInstallments::sumOf($rows), 2)]));

            return;
        }

        // One transaction: the agreement and its schedule are saved together or not at all.
        $agreement = DB::transaction(function () use ($existing, $validated, $rows): DonorProposal {
            $agreement = app(SaveFundingAgreement::class)->handle($existing, $validated);

            // Clearing a schedule that exists is refused by the action; an agreement
            // that never had one (a legacy agreement with manual tranches) is left alone.
            if ($rows !== [] || $agreement->installments()->exists()) {
                app(SaveAgreementInstallments::class)->handle($agreement, $rows);
            }

            return $agreement;
        });

        $isCreate = $agreement->wasRecentlyCreated;

        $this->showAgreementModal = false;
        $this->resetAgreementForm();
        $this->agreementSaved($agreement, $isCreate);

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('funding_agreements.funding_agreement_recorded') : __('funding_agreements.funding_agreement_updated'),
        );
    }

    /**
     * The rows in the shape SaveAgreementInstallments takes: the sequence is
     * the position, the currency is the agreement's and never typed per row.
     *
     * @return list<array{sequence: int, amount: string, currency: string, scheduled_date: string}>
     */
    private function installmentPayload(string $currency): array
    {
        $rows = [];

        foreach (array_values($this->installments) as $index => $row) {
            $rows[] = [
                'sequence' => $index + 1,
                'amount' => trim((string) ($row['amount'] ?? '')),
                'currency' => $currency,
                'scheduled_date' => trim((string) ($row['scheduled_date'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Ask for confirmation inside the open edit form.
     */
    public function confirmAgreementDelete(): void
    {
        Gate::authorize('funding-agreements.delete');

        $this->confirmingAgreementDelete = $this->editingAgreementId !== null;
    }

    public function cancelAgreementDelete(): void
    {
        $this->confirmingAgreementDelete = false;
    }

    /**
     * Delete the agreement being edited, only while nothing hangs off it.
     */
    public function deleteAgreement(): void
    {
        Gate::authorize('funding-agreements.delete');

        if ($this->editingAgreementId === null) {
            return;
        }

        $agreement = $this->scopedAgreement($this->editingAgreementId);

        if ($this->agreementDeleteBlocker !== null) {
            $this->confirmingAgreementDelete = false;

            Flux::toast(variant: 'danger', text: $this->agreementDeleteBlocker);

            return;
        }

        if (! app(DeleteFundingAgreement::class)->handle($agreement)) {
            $this->confirmingAgreementDelete = false;

            return;
        }

        $this->showAgreementModal = false;
        $this->resetAgreementForm();
        $this->agreementDeleted($agreement->id);

        Flux::toast(variant: 'success', text: __('funding_agreements.funding_agreement_deleted'));
    }

    public function closeAgreementModal(): void
    {
        $this->showAgreementModal = false;
        $this->resetAgreementForm();
    }

    /**
     * An agreement by id, re-scoped to the proposal this page is pinned to.
     */
    protected function scopedAgreement(int $agreementId): DonorProposal
    {
        return DonorProposal::query()
            ->when($this->agreementProposalScope() !== null, fn ($query) => $query->where('proposal_id', $this->agreementProposalScope()))
            ->when($this->agreementDonorScope() !== null, fn ($query) => $query->where('donor_id', $this->agreementDonorScope()))
            ->findOrFail($agreementId);
    }

    protected function resetAgreementForm(): void
    {
        $this->resetValidation();
        $this->editingAgreementId = null;
        $this->confirmingAgreementDelete = false;
        $this->agreementProposalId = $this->agreementProposalScope() === null ? '' : (string) $this->agreementProposalScope();
        $this->budget = '';
        $this->agreementCurrency = 'USD';
        $this->externalProjectNumber = '';
        $this->installments = [];
        $this->confirmInstallmentSum = false;
        unset($this->agreementDeleteBlocker, $this->agreementStatusWarning, $this->agreementCurrencyLocked, $this->agreementDonorName, $this->agreementProposalLabel, $this->proposalOptions, $this->installmentsTotal, $this->installmentsDifferFromBudget);
    }
}
