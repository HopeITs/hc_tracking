<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The register's add and edit form, and its delete confirmation. A starting
 * status is chosen only on create (it writes the first ProposalStatusNote);
 * later moves go through ManagesProposalStages.
 *
 * Used by proposals/index only. The page uses WithPagination (resetPage).
 */
trait ManagesProposalForm
{
    public bool $showProposalModal = false;

    #[Locked]
    public ?int $editingProposalId = null;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingProposalId = null;

    public string $name = '';

    public string $proposalDonorId = '';

    public string $internalProjectNumber = '';

    public string $plannedDateBegin = '';

    public string $plannedDateEnd = '';

    public string $actualDateBegin = '';

    public string $actualDateEnd = '';

    public string $proposalStatus = 'concept note';

    public string $notes = '';

    /**
     * Whether this proposal may be deleted.
     *
     * donor_proposal.proposal_id RESTRICTs on delete, deliberately: without it
     * one proposal delete would reach through the agreement into
     * transfers_tracking and erase every record of money received. The UI
     * refuses first so the user gets a reason instead of a query exception.
     */
    protected function canDelete(int $proposalId): bool
    {
        $proposal = Proposal::query()
            ->select(['id'])
            ->withCount('donorProposals')
            ->find($proposalId);

        return $proposal !== null && $proposal->donor_proposals_count === 0;
    }

    /**
     * The donors a proposal can be assigned to.
     *
     * Interim: the v2 proposal form replaces this select.
     *
     * @return Collection<int, Donor>
     */
    #[Computed]
    public function proposalDonors(): Collection
    {
        return Donor::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Proposal::class);

        $this->resetProposalForm();
        $this->showProposalModal = true;
    }

    public function openEditModal(int $proposalId): void
    {
        $proposal = Proposal::query()
            ->select(['id', 'name', 'donor_id', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'notes'])
            ->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->editingProposalId = $proposal->id;
        $this->name = $proposal->name;
        $this->proposalDonorId = (string) $proposal->donor_id;
        $this->internalProjectNumber = $proposal->internal_project_number;
        $this->plannedDateBegin = $proposal->planned_date_begin?->toDateString() ?? '';
        $this->plannedDateEnd = $proposal->planned_date_end?->toDateString() ?? '';
        $this->actualDateBegin = $proposal->actual_date_begin?->toDateString() ?? '';
        $this->actualDateEnd = $proposal->actual_date_end?->toDateString() ?? '';
        $this->notes = $proposal->notes ?? '';
        $this->showProposalModal = true;
    }

    public function saveProposal(): void
    {
        $this->name = trim($this->name);
        $this->internalProjectNumber = trim($this->internalProjectNumber);
        $this->notes = trim($this->notes);

        $storedNumber = $this->editingProposalId === null
            ? null
            : Proposal::query()->whereKey($this->editingProposalId)->value('internal_project_number');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'proposalDonorId' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'internalProjectNumber' => [
                'required',
                'string',
                'max:50',
                Rule::unique('proposals', 'internal_project_number')->ignore($this->editingProposalId),
                // TEMP- marks the placeholders the migration wrote. A proposal
                // still holding one saves unchanged, but nobody types a new one.
                function (string $attribute, mixed $value, Closure $fail) use ($storedNumber): void {
                    if (Proposal::isPlaceholderNumber((string) $value) && $value !== $storedNumber) {
                        $fail(__('proposals.internal_project_number_prefix_reserved'));
                    }
                },
            ],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'actualDateBegin' => ['nullable', 'date'],
            'actualDateEnd' => ['nullable', 'date', 'after_or_equal:actualDateBegin'],
            'proposalStatus' => [$this->editingProposalId === null ? 'required' : 'nullable', new Enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('proposals.enter_a_proposal_name'),
            'proposalDonorId.required' => __('proposals.choose_the_donor'),
            'plannedDateBegin.required' => __('proposals.set_the_planned_start_date'),
            'plannedDateEnd.required' => __('proposals.set_the_planned_end_date'),
            'plannedDateEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
            'actualDateEnd.after_or_equal' => __('proposals.the_actual_end_cannot_fall_before_the_actual_start'),
        ]);

        $proposal = $this->editingProposalId === null
            ? new Proposal
            : Proposal::query()->findOrFail($this->editingProposalId);

        Gate::authorize($proposal->exists ? 'update' : 'create', $proposal->exists ? $proposal : Proposal::class);

        // The agreement's donor must stay the proposal's donor, so the donor
        // is locked once an agreement exists (the composite key would refuse).
        if ($proposal->exists && (int) $validated['proposalDonorId'] !== $proposal->donor_id && $proposal->donorProposals()->exists()) {
            $this->addError('proposalDonorId', __('proposals.the_donor_is_fixed_by_the_funding_agreement'));

            return;
        }

        // Status is only chosen when the proposal is first recorded. After that
        // it moves through recordStageChange(), which writes the audit note
        // alongside it, so the edit form never touches it.
        try {
            DB::transaction(function () use ($proposal, $validated): void {
                $proposal->fill([
                    'name' => $validated['name'],
                    'donor_id' => (int) $validated['proposalDonorId'],
                    'internal_project_number' => $validated['internalProjectNumber'],
                    'planned_date_begin' => $validated['plannedDateBegin'],
                    'planned_date_end' => $validated['plannedDateEnd'],
                    'actual_date_begin' => $validated['actualDateBegin'] === '' ? null : $validated['actualDateBegin'],
                    'actual_date_end' => $validated['actualDateEnd'] === '' ? null : $validated['actualDateEnd'],
                    'notes' => $validated['notes'] === '' ? null : $validated['notes'],
                ]);

                if (! $proposal->exists) {
                    $proposal->status = ProposalStatus::from($validated['proposalStatus']);
                }

                $proposal->save();

                if ($proposal->wasRecentlyCreated) {
                    ProposalStatusNote::query()->create([
                        'proposal_id' => $proposal->id,
                        'status' => $proposal->status,
                        'context' => __('proposals.proposal_recorded_note'),
                        'date' => now()->toDateString(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two people saved the same new number at the same moment: both
            // passed validation and the unique index refused the second.
            if (! str_contains($exception->getMessage(), 'proposals_internal_project_number_unique')) {
                throw $exception;
            }

            $this->addError('internalProjectNumber', __('validation.unique', ['attribute' => __('validation.attributes.internalProjectNumber')]));

            return;
        }

        $wasRecentlyCreated = $proposal->wasRecentlyCreated;

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
    }

    public function confirmDelete(int $proposalId): void
    {
        Gate::authorize('delete', Proposal::query()->findOrFail($proposalId));

        $this->deletingProposalId = $proposalId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingProposalId = null;
        $this->showDeleteModal = false;
    }

    public function deleteProposal(): void
    {
        if ($this->deletingProposalId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingProposalId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('proposals.this_proposal_has_a_funding_agreement_remove_the_agreement_f'),
            );

            return;
        }

        $proposal = Proposal::query()->findOrFail($this->deletingProposalId);

        Gate::authorize('delete', $proposal);

        $proposal->delete();

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('proposals.proposal_deleted'));
    }

    public function closeProposalModal(): void
    {
        $this->showProposalModal = false;
        $this->resetProposalForm();
    }

    protected function resetProposalForm(): void
    {
        $this->resetValidation();
        $this->editingProposalId = null;
        $this->name = '';
        $this->proposalDonorId = '';
        $this->internalProjectNumber = '';
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->actualDateBegin = '';
        $this->actualDateEnd = '';
        $this->proposalStatus = 'concept note';
        $this->notes = '';
    }
}
