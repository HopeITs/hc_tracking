<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\Currency;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
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
 * so a tampered payload cannot file the agreement against another proposal.
 */
trait ManagesAgreementForm
{
    public bool $showAgreementModal = false;

    public ?int $editingAgreementId = null;

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
     * The proposal every agreement on this page must belong to, or null when
     * the page may file against any proposal.
     */
    protected function agreementProposalScope(): ?int
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
        return Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
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

        $agreement = DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->when($this->agreementProposalScope() !== null, fn ($query) => $query->where('proposal_id', $this->agreementProposalScope()))
            ->findOrFail($agreementId);

        $this->resetValidation();
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

        $validated = $this->validate([
            'agreementDonorId' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'agreementProposalId' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
            'budget' => ['required', 'numeric', 'min:0'],
            'agreementCurrency' => ['required', new Enum(Currency::class)],
            'projectNum' => ['nullable', 'string', 'max:50'],
        ], [
            'agreementDonorId.required' => __('funding_agreements.select_the_donor'),
            'agreementProposalId.required' => __('funding_agreements.select_the_proposal_being_funded'),
            'budget.required' => __('funding_agreements.enter_the_agreed_budget'),
            'budget.min' => __('funding_agreements.a_budget_cannot_be_negative'),
            'agreementCurrency.required' => __('funding_agreements.select_the_currency_this_budget_is_stated_in'),
        ]);

        $agreement = $this->editingAgreementId === null
            ? new DonorProposal
            : DonorProposal::query()
                ->when($this->agreementProposalScope() !== null, fn ($query) => $query->where('proposal_id', $this->agreementProposalScope()))
                ->findOrFail($this->editingAgreementId);

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

    public function closeAgreementModal(): void
    {
        $this->showAgreementModal = false;
        $this->resetAgreementForm();
    }

    protected function resetAgreementForm(): void
    {
        $this->resetValidation();
        $this->editingAgreementId = null;
        $this->agreementDonorId = '';
        $this->agreementProposalId = $this->agreementProposalScope() === null ? '' : (string) $this->agreementProposalScope();
        $this->budget = '';
        $this->agreementCurrency = 'USD';
        $this->projectNum = '';
    }
}
