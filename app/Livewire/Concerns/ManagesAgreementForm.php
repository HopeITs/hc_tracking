<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;

/**
 * The funding agreement form, shared by the agreement directory and the
 * proposal page so an agreement is recorded and edited by one set of rules.
 *
 * The using component renders resources/views/components/funding/agreement-modal
 * and decides what happens after a save through agreementSaved(). A page that
 * lives inside one proposal pins the form to it through agreementProposalScope(),
 * and a page that lives inside one donor pins it through agreementDonorScope(),
 * so a tampered payload cannot file the agreement against another record.
 */
trait ManagesAgreementForm
{
    /** donor_proposal.budget is DECIMAL(14,2). */
    public const MAX_BUDGET = 999999999999.99;

    public bool $showAgreementModal = false;

    public ?int $editingAgreementId = null;

    public bool $confirmingAgreementDelete = false;

    public string $agreementDonorId = '';

    public string $agreementProposalId = '';

    public string $budget = '';

    public string $agreementCurrency = 'USD';

    public string $projectNum = '';

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

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()->select(['id', 'name', 'status'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * A warning when the chosen proposal has not been approved by a donor, or
     * was rejected. Recording is still allowed: paperwork is sometimes signed
     * before the status catches up.
     */
    #[Computed]
    public function agreementProposalWarning(): ?string
    {
        $proposalId = (int) ($this->agreementProposalScope() ?? $this->agreementProposalId);

        if ($proposalId === 0) {
            return null;
        }

        $status = Proposal::query()->whereKey($proposalId)->first(['id', 'status'])?->status;

        if ($status === null || $status->isApprovedByDonor()) {
            return null;
        }

        return __('funding_agreements.proposal_not_approved_warning', ['status' => $status->label()]);
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
        $this->showAgreementModal = true;
    }

    public function openAgreementEdit(int $agreementId): void
    {
        Gate::authorize('funding-agreements.update');

        $agreement = $this->scopedAgreement($agreementId);

        $this->resetValidation();
        $this->confirmingAgreementDelete = false;
        $this->editingAgreementId = $agreement->id;
        $this->agreementDonorId = (string) $agreement->donor_id;
        $this->agreementProposalId = (string) $agreement->proposal_id;
        $this->budget = (string) $agreement->budget;
        $this->agreementCurrency = $agreement->currency->value;
        $this->projectNum = $agreement->project_num ?? '';
        $this->showAgreementModal = true;
    }

    public function saveAgreement(): void
    {
        Gate::authorize($this->editingAgreementId === null ? 'funding-agreements.create' : 'funding-agreements.update');

        $this->projectNum = trim($this->projectNum);

        if ($this->agreementProposalScope() !== null) {
            $this->agreementProposalId = (string) $this->agreementProposalScope();
        }

        if ($this->agreementDonorScope() !== null) {
            $this->agreementDonorId = (string) $this->agreementDonorScope();
        }

        $existing = $this->editingAgreementId === null ? null : $this->scopedAgreement($this->editingAgreementId);

        $validated = $this->validate([
            'agreementDonorId' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'agreementProposalId' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
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
            'projectNum' => ['nullable', 'string', 'max:50'],
        ], [
            'agreementDonorId.required' => __('funding_agreements.select_the_donor'),
            'agreementProposalId.required' => __('funding_agreements.select_the_proposal_being_funded'),
            'budget.required' => __('funding_agreements.enter_the_agreed_budget'),
            'budget.min' => __('funding_agreements.a_budget_cannot_be_negative'),
            'budget.max' => __('funding_agreements.a_budget_is_too_large'),
            'agreementCurrency.required' => __('funding_agreements.select_the_currency_this_budget_is_stated_in'),
        ]);

        $agreement = $existing ?? new DonorProposal;

        $agreement->fill([
            'donor_id' => (int) $validated['agreementDonorId'],
            'proposal_id' => (int) $validated['agreementProposalId'],
            'budget' => $validated['budget'],
            'currency' => $validated['agreementCurrency'],
            'project_num' => $validated['projectNum'] === '' ? null : $validated['projectNum'],
        ])->save();

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

        $agreement->delete();

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
        $this->agreementDonorId = $this->agreementDonorScope() === null ? '' : (string) $this->agreementDonorScope();
        $this->agreementProposalId = $this->agreementProposalScope() === null ? '' : (string) $this->agreementProposalScope();
        $this->budget = '';
        $this->agreementCurrency = 'USD';
        $this->projectNum = '';
        unset($this->agreementDeleteBlocker, $this->agreementProposalWarning, $this->agreementCurrencyLocked);
    }
}
