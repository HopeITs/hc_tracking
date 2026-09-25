<?php

use App\Enums\ArStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use App\Notifications\UnreadSubjects;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.proposals')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    #[Url(except: '')]
    public string $donor = '';

    public bool $showProposalModal = false;
    public ?int $editingProposalId = null;

    public bool $showDeleteModal = false;
    public ?int $deletingProposalId = null;

    public bool $showStageModal = false;
    public ?int $stageProposalId = null;
    public string $newStatus = '';
    public string $statusContext = '';
    public string $statusDate = '';

    public string $name = '';
    public string $plannedDateBegin = '';
    public string $plannedDateEnd = '';
    public string $actualDateBegin = '';
    public string $actualDateEnd = '';
    public string $proposalStatus = 'concept note';
    public string $notes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPhase(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->phase = '';
        $this->donor = '';
        $this->resetPage();
    }

    /**
     * Toggle the phase filter from the pipeline rail.
     */
    public function togglePhase(string $phase): void
    {
        $this->phase = $this->phase === $phase ? '' : (ProposalPhase::tryFrom($phase)?->value ?? '');
        $this->resetPage();
    }

    /**
     * Toggle the donor filter from a donor node in the funding flow.
     */
    public function toggleDonor(int $donorId): void
    {
        $this->donor = $this->donor === (string) $donorId ? '' : (string) $donorId;
        $this->resetPage();
    }

    /**
     * Proposals matching every active filter.
     *
     * The pipeline rail passes $withPhase false so it can still count the
     * phases the phase filter is hiding; the rail is how that filter is chosen.
     *
     * @return Builder<Proposal>
     */
    protected function filteredQuery(bool $withPhase = true): Builder
    {
        $search = trim($this->search);
        $donorId = (int) $this->donor;

        return Proposal::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%');
                });
            })
            ->when(ProposalStatus::tryFrom($this->status), fn (Builder $query, ProposalStatus $status) => $query->where('status', $status))
            // There is no phase column: a phase is a set of statuses, derived
            // from the same match that ProposalStatus::phase() uses.
            ->when(
                $withPhase && ProposalPhase::tryFrom($this->phase) !== null,
                fn (Builder $query) => $query->whereIn('status', ProposalPhase::from($this->phase)->statuses())
            )
            ->when($donorId > 0, fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('donor_id', $donorId)));
    }

    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'proposal');
    }

    /** @return LengthAwarePaginator<int, Proposal> */
    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        $today = now()->toDateString();

        return $this->filteredQuery()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'updated_at'])
            ->with([
                'donorProposals' => fn ($query) => $query->select(['id', 'proposal_id', 'donor_id', 'budget', 'currency', 'project_num'])->orderBy('id'),
                'donorProposals.donor:id,name',
                'donorProposals.transfers:id,donor_proposal_id,ar_status,payment_amount,amount_transfered_currency,net_amount_received,amount_received_currency,scheduled_date,transfer_date',
                'projects' => fn ($query) => $query->select(['projects.id', 'projects.name', 'projects.status'])->orderBy('projects.name'),
                'statusNotes' => fn ($query) => $query->select(['id', 'proposal_id', 'status', 'context', 'date'])->orderByDesc('date')->orderByDesc('id')->limit(1),
            ])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($query) => $query->where('status', TaskStatus::Done),
                'tasks as overdue_tasks_count' => fn ($query) => $query->where('status', '!=', TaskStatus::Done)->whereDate('due_date', '<', $today),
                'statusNotes',
                'planIndicators',
            ])
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, int> */
    #[Computed]
    public function filteredProposalIds(): Collection
    {
        return $this->filteredQuery()->pluck('id');
    }

    /**
     * Money committed, received and still pending, per currency, across every
     * proposal the filters match (not only the visible page).
     *
     * Same definitions as the dashboard's funding position: received is net
     * money in its received currency, pending is the scheduled payment in its
     * transfer currency. Currencies are never added together.
     *
     * @return Collection<int, array{currency: string, committed: float, received: float, pending: float, pendingCount: int, unscheduled: float}>
     */
    #[Computed]
    public function fundingPosition(): Collection
    {
        $ids = $this->filteredProposalIds;

        $committed = DonorProposal::query()
            ->whereIn('proposal_id', $ids)
            ->selectRaw('currency, sum(budget) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $transfers = fn () => TransfersTracking::query()
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('proposal_id', $ids));

        $received = $transfers()
            ->where('ar_status', ArStatus::Received)
            ->selectRaw('amount_received_currency as currency, sum(net_amount_received) as total')
            ->groupBy('amount_received_currency')
            ->pluck('total', 'currency');

        $pending = $transfers()
            ->where('ar_status', ArStatus::Pending)
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total, count(*) as transfers')
            ->groupBy('amount_transfered_currency')
            ->get()
            ->keyBy('currency');

        // What the tranches sent in each agreement's own currency already
        // cover of its budget, whatever became of them: fees, a missing net
        // amount or a failed transfer never turn money back into unscheduled.
        $scheduled = TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.proposal_id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.currency as currency, sum(transfers_tracking.payment_amount) as total')
            ->groupBy('donor_proposal.currency')
            ->pluck('total', 'currency');

        return $committed->keys()
            ->concat($received->keys())
            ->concat($pending->keys())
            ->map(fn ($currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $received, $pending, $scheduled): array {
                $committedTotal = (float) ($committed[$currency] ?? 0);
                $receivedTotal = (float) ($received[$currency] ?? 0);
                $pendingTotal = (float) ($pending[$currency]->total ?? 0);

                return [
                    'currency' => $currency,
                    'committed' => $committedTotal,
                    'received' => $receivedTotal,
                    'pending' => $pendingTotal,
                    'pendingCount' => (int) ($pending[$currency]->transfers ?? 0),
                    'unscheduled' => max(0, $committedTotal - (float) ($scheduled[$currency] ?? 0)),
                ];
            });
    }

    /**
     * The five phases with how many proposals sit in each and what they hold.
     *
     * Ignores the phase filter itself so every stop stays selectable.
     *
     * @return list<array{phase: ProposalPhase, count: int, rejected: int, money: array<string, float>}>
     */
    #[Computed]
    public function pipeline(): array
    {
        $rows = $this->filteredQuery(withPhase: false)
            ->leftJoin('donor_proposal', 'donor_proposal.proposal_id', '=', 'proposals.id')
            ->selectRaw('proposals.status, donor_proposal.currency, count(distinct proposals.id) as proposals, sum(donor_proposal.budget) as budget')
            ->groupBy('proposals.status', 'donor_proposal.currency')
            ->get();

        $counts = $this->filteredQuery(withPhase: false)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(function (ProposalPhase $phase) use ($rows, $counts): array {
            $statuses = array_map(fn (ProposalStatus $status): string => $status->value, $phase->statuses());

            $money = $rows
                ->filter(fn ($row): bool => in_array($row->status instanceof ProposalStatus ? $row->status->value : $row->status, $statuses, true) && $row->currency !== null)
                ->groupBy(fn ($row): string => (string) $row->currency)
                ->map(fn (Collection $group): float => (float) $group->sum('budget'))
                ->sortKeys()
                ->all();

            return [
                'phase' => $phase,
                'count' => (int) collect($statuses)->sum(fn (string $status): int => (int) ($counts[$status] ?? 0)),
                'rejected' => $phase === ProposalPhase::Implementation ? (int) ($counts[ProposalStatus::Rejected->value] ?? 0) : 0,
                'money' => $money,
            ];
        }, ProposalPhase::cases());
    }

    /**
     * The donor → proposal → project graph behind the funding flow.
     *
     * One graph per currency, because ribbon widths are budgets and budgets in
     * different currencies cannot share a scale. Proposal → project links carry
     * no money (the system does not split a budget across projects), so they
     * are drawn as threads, not ribbons.
     *
     * @return array{currencies: list<array{currency: string, total: float, donors: list<array{key: string, id: int, name: string, total: float, agreements: int}>, proposals: list<array{key: string, id: int, name: string, total: float, status: ProposalStatus}>, projects: list<array{key: string, id: int, name: string}>, graph: array{order: list<string>, money: list<array{from: string, to: string, amount: float}>, links: list<array{from: string, to: string}>}}>, unfunded: list<array{id: int, name: string}>}
     */
    #[Computed]
    public function flow(): array
    {
        $proposals = Proposal::query()
            ->whereIn('id', $this->filteredProposalIds)
            ->select(['id', 'name', 'status', 'planned_date_begin'])
            ->with([
                'donorProposals:id,proposal_id,donor_id,budget,currency',
                'donorProposals.donor:id,name',
                'projects' => fn ($query) => $query->select(['projects.id', 'projects.name'])->orderBy('projects.name'),
            ])
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->get();

        $currencies = $proposals
            ->flatMap(fn (Proposal $proposal) => $proposal->donorProposals->map(fn (DonorProposal $agreement): string => $agreement->currency->value))
            ->unique()
            ->sort()
            ->values();

        $graphs = $currencies->map(function (string $currency) use ($proposals): array {
            $agreements = $proposals->flatMap(fn (Proposal $proposal) => $proposal->donorProposals
                ->filter(fn (DonorProposal $agreement): bool => $agreement->currency->value === $currency));

            $donors = $agreements
                ->groupBy('donor_id')
                ->map(fn (Collection $group): array => [
                    'key' => 'd'.$group->first()->donor_id,
                    'id' => (int) $group->first()->donor_id,
                    'name' => $group->first()->donor->name,
                    'total' => (float) $group->sum('budget'),
                    'agreements' => $group->count(),
                ])
                ->sortByDesc('total')
                ->values();

            $funded = $proposals
                ->map(fn (Proposal $proposal): array => [
                    'key' => 'p'.$proposal->id,
                    'id' => $proposal->id,
                    'name' => $proposal->name,
                    'status' => $proposal->status,
                    'total' => (float) $proposal->donorProposals->filter(fn (DonorProposal $agreement): bool => $agreement->currency->value === $currency)->sum('budget'),
                    'projects' => $proposal->projects,
                ])
                ->filter(fn (array $proposal): bool => $proposal['total'] > 0)
                // Order proposals by their largest donor so ribbons cross as little as possible.
                ->sortBy(function (array $proposal) use ($donors, $agreements): int {
                    $mainDonor = $agreements->where('proposal_id', $proposal['id'])->sortByDesc('budget')->first()?->donor_id;

                    return (int) $donors->search(fn (array $donor): bool => $donor['id'] === $mainDonor);
                })
                ->values();

            // Each project sits at the average row of the proposals it is linked to, so threads cross as little as possible.
            $projects = $funded
                ->flatMap(fn (array $proposal, int $row) => $proposal['projects']->map(fn ($project): array => ['project' => $project, 'row' => $row]))
                ->groupBy(fn (array $link): int => $link['project']->id)
                ->map(fn (Collection $links): array => [
                    'key' => 'j'.$links->first()['project']->id,
                    'id' => $links->first()['project']->id,
                    'name' => $links->first()['project']->name,
                    'row' => $links->avg('row'),
                ])
                ->sortBy([['row', 'asc'], ['name', 'asc']])
                ->map(fn (array $project): array => collect($project)->except('row')->all())
                ->values();

            $money = $agreements
                ->filter(fn (DonorProposal $agreement): bool => $funded->contains('id', $agreement->proposal_id))
                ->groupBy(fn (DonorProposal $agreement): string => $agreement->donor_id.'-'.$agreement->proposal_id)
                ->map(fn (Collection $group): array => [
                    'from' => 'd'.$group->first()->donor_id,
                    'to' => 'p'.$group->first()->proposal_id,
                    'amount' => (float) $group->sum('budget'),
                ])
                ->values();

            $links = $funded->flatMap(fn (array $proposal) => $proposal['projects']->map(fn ($project): array => [
                'from' => 'p'.$proposal['id'],
                'to' => 'j'.$project->id,
            ]))->values();

            return [
                'currency' => $currency,
                'total' => (float) $agreements->sum('budget'),
                'donors' => $donors->all(),
                'proposals' => $funded->map(fn (array $proposal): array => collect($proposal)->except('projects')->all())->all(),
                'projects' => $projects->all(),
                'graph' => [
                    'order' => $donors->pluck('key')->concat($funded->pluck('key'))->concat($projects->pluck('key'))->all(),
                    'money' => $money->all(),
                    'links' => $links->all(),
                ],
            ];
        });

        return [
            'currencies' => $graphs->all(),
            'unfunded' => $proposals
                ->filter(fn (Proposal $proposal): bool => $proposal->donorProposals->isEmpty())
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name])
                ->values()
                ->all(),
        ];
    }

    #[Computed]
    public function donorFilterName(): ?string
    {
        $donorId = (int) $this->donor;

        return $donorId > 0 ? Donor::query()->whereKey($donorId)->value('name') : null;
    }

    /**
     * Money on one agreement, split the way its bar draws it.
     *
     * Only transfers in the agreement's own currency fill its bar; money that
     * arrived in another currency is reported beside it, never mixed in.
     *
     * @return array{received: float, pending: float, unscheduled: float, share: int, otherCurrencies: array<string, float>, nextDue: ?\Carbon\CarbonImmutable}
     */
    public function agreementMoney(DonorProposal $agreement): array
    {
        $currency = $agreement->currency->value;
        $received = $agreement->transfers->where('ar_status', ArStatus::Received);
        $pending = $agreement->transfers->where('ar_status', ArStatus::Pending);

        $receivedHere = (float) $received->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)->sum('net_amount_received');
        $pendingHere = (float) $pending->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)->sum('payment_amount');
        $budget = (float) $agreement->budget;

        return [
            'received' => $receivedHere,
            'pending' => $pendingHere,
            // Every tranche sent in this currency covers part of the budget,
            // whatever became of it; fees and failures are not "unscheduled".
            'unscheduled' => max(0, $budget - (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
                ->sum('payment_amount')),
            'share' => $budget > 0 ? (int) floor($receivedHere / $budget * 100) : 0,
            'otherCurrencies' => $received
                ->reject(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
                ->groupBy(fn (TransfersTracking $transfer): string => (string) $transfer->amount_received_currency?->value)
                ->map(fn (Collection $group): float => (float) $group->sum('net_amount_received'))
                ->all(),
            // Only money still to be sent has a due date; a sent tranche is in transit.
            'nextDue' => $pending->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first()?->scheduled_date,
        ];
    }

    /**
     * Whether this proposal may be deleted.
     *
     * donor_proposal.proposal_id RESTRICTs on delete, deliberately: without it
     * one proposal delete would reach through the agreement into
     * transfers_tracking and erase every record of money received. The UI
     * refuses first so the user gets a reason instead of a query exception.
     */
    public function canDelete(int $proposalId): bool
    {
        $proposal = Proposal::query()
            ->select(['id'])
            ->withCount('donorProposals')
            ->find($proposalId);

        return $proposal !== null && $proposal->donor_proposals_count === 0;
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
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'notes'])
            ->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->editingProposalId = $proposal->id;
        $this->name = $proposal->name;
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
        $this->notes = trim($this->notes);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'actualDateBegin' => ['nullable', 'date'],
            'actualDateEnd' => ['nullable', 'date', 'after_or_equal:actualDateBegin'],
            'proposalStatus' => [$this->editingProposalId === null ? 'required' : 'nullable', new Enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('proposals.enter_a_proposal_name'),
            'plannedDateBegin.required' => __('proposals.set_the_planned_start_date'),
            'plannedDateEnd.required' => __('proposals.set_the_planned_end_date'),
            'plannedDateEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
            'actualDateEnd.after_or_equal' => __('proposals.the_actual_end_cannot_fall_before_the_actual_start'),
        ]);

        $proposal = $this->editingProposalId === null
            ? new Proposal
            : Proposal::query()->findOrFail($this->editingProposalId);

        Gate::authorize($proposal->exists ? 'update' : 'create', $proposal->exists ? $proposal : Proposal::class);

        // Status is only chosen when the proposal is first recorded. After that
        // it moves through recordStageChange(), which writes the audit note
        // alongside it, so the edit form never touches it.
        DB::transaction(function () use ($proposal, $validated): void {
            $proposal->fill([
                'name' => $validated['name'],
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

        $wasRecentlyCreated = $proposal->wasRecentlyCreated;

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
    }

    /**
     * Open the stage move for one proposal, preset to its next lifecycle stage.
     */
    public function openStageModal(int $proposalId): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->stageProposalId = $proposal->id;
        $this->newStatus = ($proposal->status->next() ?? $proposal->status)->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStageModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction, exactly as on the proposal page:
     * the live status and its audit trail must never disagree.
     */
    public function recordStageChange(): void
    {
        if ($this->stageProposalId === null) {
            return;
        }

        Gate::authorize('update', Proposal::query()->findOrFail($this->stageProposalId));

        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('proposals.choose_the_new_status'),
            'statusContext.required' => __('proposals.record_why_the_status_changed'),
            'statusDate.required' => __('proposals.set_the_date_this_change_took_effect'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->stageProposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->closeStageModal();

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }

    public function closeStageModal(): void
    {
        $this->resetValidation();
        $this->showStageModal = false;
        $this->stageProposalId = null;
        $this->statusContext = '';
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
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->actualDateBegin = '';
        $this->actualDateEnd = '';
        $this->proposalStatus = 'concept note';
        $this->notes = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $phase !== '' || $donor !== '';
    $hasAnyProposal = $filtersAreActive || $this->proposals->total() > 0;
    $lifecycle = ProposalStatus::lifecycle();
    $mutedText = 'text-secondary-medium dark:text-secondary-soft';
    $inkText = 'text-secondary-dark dark:text-white';
    $receivedText = 'text-funding';
    $hairline = 'border-secondary-soft dark:border-secondary-medium';
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => Number::abbreviate($value, maxPrecision: 1);
    $matchingCount = $this->filteredProposalIds->count();
    // The flow names every matching proposal but the list below shows one page
    // of them: a proposal on this page is scrolled to, any other one opened.
    $pageProposalIds = $this->proposals->getCollection()->modelKeys();
    $proposalHref = fn (int $id): string => in_array($id, $pageProposalIds, true) ? '#proposal-'.$id : route('proposals.show', tap(new Proposal, fn ($proposal) => $proposal->id = $id));
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="proposal-pipeline-heading">
    <x-ui.page-header
        heading-id="proposal-pipeline-heading"
        :title="__('common.proposals')"
        :count="$this->proposals->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('proposals.search_proposals')" :placeholder="__('proposals.name_or_notes')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'proposal-status-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('proposals.phase')" wire:model.live="phase" :value="$phase">
                <flux:select.option value="">{{ __('proposals.all_phases') }}</flux:select.option>
                @foreach (ProposalPhase::cases() as $phaseCase)
                    <flux:select.option :value="$phaseCase->value" :wire:key="'proposal-phase-'.$phaseCase->value">{{ $phaseCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('create', App\Models\Proposal::class)
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-proposal-button">
                {{ __('proposals.add_proposal') }}
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($filtersAreActive)
        <div class="flex flex-wrap items-center gap-2 text-sm" data-active-filters>
            <span class="{{ $mutedText }}">{{ trans_choice('proposals.count_matching_proposal', $matchingCount, ['count' => $matchingCount]) }}</span>
            @if ($this->donorFilterName !== null)
                <button type="button" wire:click="toggleDonor({{ (int) $donor }})" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-white" aria-label="{{ __('proposals.remove_donor_filter', ['name' => $this->donorFilterName]) }}" data-donor-chip>
                    {{ __('proposals.funded_by', ['name' => $this->donorFilterName]) }}
                    <flux:icon.x-mark variant="micro" class="size-3.5" />
                </button>
            @endif
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        </div>
    @endif

    @if (! $hasAnyProposal)
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_proposals_yet')" :body="__('proposals.add_the_first_proposal_to_open_the_pipeline')" />
    @else
        {{-- Where the money stands: one line per currency, never summed across them. --}}
        <section aria-labelledby="money-heading" class="pt-3" data-funding-position>
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="money-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.where_the_money_stands') }}</h2>
                <p class="text-xs {{ $mutedText }}">{{ __('proposals.budgets_are_shown_per_currency_they_are_never_added_together') }}</p>
            </div>

            @forelse ($this->fundingPosition as $line)
                @php
                    $barBase = max($line['committed'], $line['received'] + $line['pending'], 1);
                    $figures = [
                        ['key' => 'committed', 'label' => __('proposals.committed'), 'value' => $line['committed'], 'tone' => $inkText, 'note' => trans_choice('proposals.across_count_proposals', $matchingCount, ['count' => $matchingCount])],
                        ['key' => 'received', 'label' => __('proposals.received'), 'value' => $line['received'], 'tone' => $receivedText, 'note' => __('proposals.percent_of_committed', ['percent' => App\Models\DonorProposal::percentLabel($line['received'], $line['committed'])])],
                        ['key' => 'pending', 'label' => __('proposals.pending'), 'value' => $line['pending'], 'tone' => 'text-status-warn', 'note' => trans_choice('proposals.count_transfers_scheduled', $line['pendingCount'], ['count' => $line['pendingCount']])],
                        ['key' => 'unscheduled', 'label' => __('proposals.not_yet_scheduled'), 'value' => $line['unscheduled'], 'tone' => $mutedText, 'note' => __('proposals.committed_with_no_transfer_yet')],
                    ];
                @endphp
                <div class="mt-5 border-t pt-5 {{ $hairline }}" wire:key="money-{{ $line['currency'] }}">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-6 @4xl/directory:grid-cols-[3.5rem_repeat(4,minmax(0,1fr))]">
                        <p class="col-span-2 text-sm font-extrabold tracking-wide @4xl/directory:col-span-1 @4xl/directory:pt-0.5 {{ $receivedText }}">{{ $line['currency'] }}</p>
                        @foreach ($figures as $figure)
                            <div class="logframe-reveal min-w-0" style="--logframe-order: {{ $loop->index }}" data-figure="{{ $figure['key'] }}">
                                <p class="text-xs font-semibold {{ $mutedText }}">{{ $figure['label'] }}</p>
                                <p
                                    class="mt-1.5 truncate text-3xl leading-none font-extrabold tracking-tight tabular-nums @6xl/directory:text-[2.625rem] {{ $figure['tone'] }}"
                                    x-data="countUp({{ (int) round($figure['value']) }})"
                                    x-text="new Intl.NumberFormat('en-US').format(display)"
                                >{{ $amount($figure['value']) }}</p>
                                <p class="mt-2 text-xs {{ $mutedText }}">{{ $figure['note'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium @4xl/directory:ms-[calc(3.5rem+1.5rem)] @4xl/directory:w-[calc(100%-5rem)]" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $line['currency'], 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                        <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ round($line['received'] / $barBase * 100, 2) }}%"></span>
                        <span class="money-grow money-pending h-full" style="width: {{ round($line['pending'] / $barBase * 100, 2) }}%"></span>
                    </div>
                </div>
            @empty
                <p class="mt-4 border-t pt-4 text-sm {{ $hairline }} {{ $mutedText }}">{{ __('proposals.no_money_committed_to_these_proposals_yet') }}</p>
            @endforelse
        </section>

        {{-- The pipeline rail: five phases on one thread; a stop filters the page. --}}
        <nav aria-label="{{ __('proposals.pipeline_phases') }}" class="border-t pt-6 {{ $hairline }}" data-pipeline>
            <ol class="relative grid grid-cols-5 gap-1">
                <span class="absolute inset-x-[10%] top-[0.4375rem] h-px bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                @foreach ($this->pipeline as $stop)
                    @php
                        $isSelected = $phase === $stop['phase']->value;
                        $isEmpty = $stop['count'] === 0;
                    @endphp
                    <li class="relative min-w-0" wire:key="phase-{{ $stop['phase']->value }}">
                        <button
                            type="button"
                            wire:click="togglePhase('{{ $stop['phase']->value }}')"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="group flex w-full flex-col items-center gap-1.5 rounded-lg px-1 pb-2 text-center transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:hover:bg-white/5"
                            data-phase-stop="{{ $stop['phase']->value }}"
                        >
                            <span @class([
                                'relative z-10 size-3.5 rounded-full border-2 transition-transform duration-200 ease-out group-hover:scale-125',
                                'border-primary bg-primary ring-4 ring-primary/15' => $isSelected,
                                'border-secondary-dark bg-secondary-dark dark:border-white dark:bg-white' => ! $isSelected && ! $isEmpty,
                                'border-secondary-light bg-white dark:bg-secondary-dark' => ! $isSelected && $isEmpty,
                            ]) aria-hidden="true"></span>
                            <span title="{{ $stop['phase']->label() }}" @class(['mt-1 w-full truncate text-[11px] font-bold @xl/directory:text-xs @3xl/directory:text-sm', 'text-primary' => $isSelected, $inkText => ! $isSelected && ! $isEmpty, $mutedText => ! $isSelected && $isEmpty])>{{ $stop['phase']->label() }}</span>
                            <span @class(['text-2xl leading-none font-extrabold tabular-nums @3xl/directory:text-3xl', 'text-secondary-light' => $isEmpty, $inkText => ! $isEmpty])>{{ $stop['count'] }}</span>
                            <span class="flex min-h-4 flex-col items-center gap-0.5 text-[11px] font-medium tabular-nums {{ $mutedText }}">
                                @foreach ($stop['money'] as $currency => $total)
                                    <span>{{ $currency }} {{ $shortAmount($total) }}</span>
                                @endforeach
                                @if ($stop['rejected'] > 0)
                                    <span class="font-semibold text-primary">{{ trans_choice('proposals.count_rejected', $stop['rejected'], ['count' => $stop['rejected']]) }}</span>
                                @endif
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- Funding flow: donors → proposals → projects. --}}
        @if (count($this->flow['currencies']) > 0 || count($this->flow['unfunded']) > 0)
            <section aria-labelledby="flow-heading" class="border-t pt-6 {{ $hairline }}" x-data="{ currency: @js($this->flow['currencies'][0]['currency'] ?? null) }" data-funding-flow>
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                    <div class="min-w-0">
                        <h2 id="flow-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.funding_flow') }}</h2>
                        <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.funding_flow_hint') }}</p>
                    </div>
                    @if (count($this->flow['currencies']) > 1)
                        <div class="inline-flex rounded-lg bg-board-head p-1 dark:bg-white/5" role="group" aria-label="{{ __('proposals.currency') }}">
                            @foreach ($this->flow['currencies'] as $graph)
                                <button type="button" x-on:click="currency = @js($graph['currency'])" :aria-pressed="(currency === @js($graph['currency'])).toString()" class="min-h-8 rounded-md px-3 text-xs font-bold tabular-nums transition-colors {{ $mutedText }} aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-sm dark:aria-pressed:bg-secondary-medium dark:aria-pressed:text-white" wire:key="flow-tab-{{ $graph['currency'] }}">{{ $graph['currency'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @foreach ($this->flow['currencies'] as $graph)
                    @php
                        $rows = max(count($graph['donors']), count($graph['proposals']), (int) ceil(count($graph['projects']) * 0.55));
                        $flowHeight = max(16, $rows * 5.25);
                        $flowKey = 'flow-'.$graph['currency'].'-'.md5(json_encode($graph['graph']).$donor);
                    @endphp
                    <div x-show="currency === @js($graph['currency'])" wire:key="{{ $flowKey }}">
                        {{-- Wide screens: the drawn flow. --}}
                        <div class="relative mt-6 hidden @3xl/directory:block" x-data="fundingFlow(@js($graph['graph']))">
                            <svg class="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden="true" wire:ignore>
                                <g class="flow-reveal" x-ref="paths"></g>
                            </svg>

                            <div class="grid grid-cols-[minmax(0,0.75fr)_minmax(3.5rem,0.75fr)_minmax(0,1.35fr)_minmax(3rem,0.6fr)_minmax(0,1.3fr)] gap-y-3">
                                <p class="type-label text-end {{ $mutedText }}">{{ __('common.donors') }}</p>
                                <span></span>
                                <p class="type-label ps-5 {{ $mutedText }}">{{ __('common.proposals') }}</p>
                                <span></span>
                                <p class="type-label ps-5 {{ $mutedText }}">{{ __('common.projects') }}</p>

                                {{-- Donors --}}
                                <ul class="flex flex-col gap-2.5" style="height: {{ $flowHeight }}rem" role="list">
                                    @foreach ($graph['donors'] as $node)
                                        @php
                                            $isFiltered = $donor === (string) $node['id'];
                                        @endphp
                                        <li class="flex min-h-15" style="flex: {{ max(1, round($node['total'] / max($graph['total'], 1) * 100, 2)) }} 1 0%" wire:key="flow-donor-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <button
                                                type="button"
                                                wire:click="toggleDonor({{ $node['id'] }})"
                                                aria-pressed="{{ $isFiltered ? 'true' : 'false' }}"
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex w-full cursor-pointer items-stretch justify-end gap-3 rounded-sm text-end transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                title="{{ __('proposals.filter_by_donor', ['name' => $node['name']]) }}"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span class="flex min-w-0 flex-col justify-center">
                                                    <span @class(['truncate text-sm font-bold underline-offset-4 group-hover:underline', 'text-primary' => $isFiltered, $inkText => ! $isFiltered])>{{ $node['name'] }}</span>
                                                    <span class="truncate text-xs tabular-nums {{ $mutedText }}">{{ $amount($node['total']) }} · {{ trans_choice('common.count_agreement', $node['agreements'], ['count' => $node['agreements']]) }}</span>
                                                </span>
                                                <span data-flow-out="{{ $node['key'] }}" @class(['w-2 shrink-0 rounded-full', 'bg-primary' => $isFiltered, 'bg-funding' => ! $isFiltered])></span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>

                                <span></span>

                                {{-- Proposals --}}
                                <ul class="flex flex-col gap-2.5" style="height: {{ $flowHeight }}rem" role="list">
                                    @foreach ($graph['proposals'] as $node)
                                        <li class="flex min-h-15" style="flex: {{ max(1, round($node['total'] / max($graph['total'], 1) * 100, 2)) }} 1 0%" wire:key="flow-proposal-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <a
                                                href="{{ $proposalHref($node['id']) }}"
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex w-full items-stretch gap-3 rounded-sm transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span data-flow-in="{{ $node['key'] }}" class="w-2 shrink-0 rounded-full bg-secondary-dark dark:bg-white"></span>
                                                <span class="flex min-w-0 flex-1 flex-col justify-center">
                                                    <span class="line-clamp-2 text-sm leading-snug font-bold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $node['name'] }}</span>
                                                    <span class="flex min-w-0 items-center gap-1.5 text-xs tabular-nums {{ $mutedText }}">
                                                        <span class="truncate">{{ $amount($node['total']) }} {{ $graph['currency'] }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span class="truncate">{{ $node['status']->label() }}</span>
                                                    </span>
                                                </span>
                                                <span data-flow-out="{{ $node['key'] }}" class="my-auto size-2 shrink-0 rounded-full bg-secondary-light"></span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>

                                <span></span>

                                {{-- Projects --}}
                                <ul class="flex flex-col justify-around gap-1" style="height: {{ $flowHeight }}rem" role="list">
                                    @forelse ($graph['projects'] as $node)
                                        <li wire:key="flow-project-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <a
                                                href="{{ route('projects.show', tap(new Project, fn ($project) => $project->id = $node['id'])) }}"
                                                wire:navigate
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex min-h-8 items-center gap-2.5 rounded-sm transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span data-flow-in="{{ $node['key'] }}" class="size-2.5 shrink-0 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark"></span>
                                                <span class="line-clamp-2 text-sm leading-snug font-semibold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $node['name'] }}</span>
                                            </a>
                                        </li>
                                    @empty
                                        <li class="ps-5 text-xs {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>

                        {{-- Narrow screens: the same chain as a stacked thread. --}}
                        <ul class="mt-4 flex flex-col gap-5 @3xl/directory:hidden" role="list">
                            @foreach ($graph['donors'] as $node)
                                <li wire:key="flow-stack-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                    <button type="button" wire:click="toggleDonor({{ $node['id'] }})" aria-pressed="{{ $donor === (string) $node['id'] ? 'true' : 'false' }}" class="flex min-h-11 w-full items-center gap-2.5 text-start">
                                        <span class="size-3 shrink-0 rounded-full bg-funding" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-bold {{ $inkText }}">{{ $node['name'] }}</span>
                                        <span class="shrink-0 text-xs font-semibold tabular-nums {{ $mutedText }}">{{ $amount($node['total']) }} {{ $graph['currency'] }}</span>
                                    </button>
                                    <ul class="ms-1.5 flex flex-col border-s-2 border-funding/30 ps-4" role="list">
                                        @foreach ($graph['graph']['money'] as $ribbon)
                                            @if ($ribbon['from'] === $node['key'])
                                                @php
                                                    $target = collect($graph['proposals'])->firstWhere('key', $ribbon['to']);
                                                @endphp
                                                <li class="flex items-center gap-2 text-sm" wire:key="flow-stack-{{ $graph['currency'] }}-{{ $ribbon['from'] }}-{{ $ribbon['to'] }}">
                                                    <a href="{{ $proposalHref($target['id']) }}" class="min-w-0 flex-1 truncate py-2.5 font-semibold hover:underline {{ $inkText }}">{{ $target['name'] }}</a>
                                                    <span class="shrink-0 text-xs tabular-nums {{ $mutedText }}">{{ $amount($ribbon['amount']) }}</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach

                @if (count($this->flow['unfunded']) > 0)
                    <p class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-dashed pt-4 text-xs {{ $hairline }} {{ $mutedText }}" data-unfunded>
                        <span class="font-semibold">{{ __('proposals.not_yet_funded') }}</span>
                        @foreach ($this->flow['unfunded'] as $unfunded)
                            <a href="{{ $proposalHref($unfunded['id']) }}" class="inline-flex min-h-8 items-center gap-1.5 font-semibold hover:underline {{ $inkText }}" wire:key="unfunded-{{ $unfunded['id'] }}">
                                <span class="size-2 rounded-full border border-dashed border-secondary-medium dark:border-secondary-soft" aria-hidden="true"></span>
                                {{ $unfunded['name'] }}
                            </a>
                        @endforeach
                    </p>
                @endif
            </section>
        @endif

        {{-- Dossiers: one proposal per row, everything it is connected to. --}}
        <section aria-labelledby="dossiers-heading" class="border-t pt-6 {{ $hairline }}">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="dossiers-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.every_proposal_and_its_connections') }}</h2>
                <p class="text-xs {{ $mutedText }}">{{ trans_choice('proposals.count_matching_proposal', $this->proposals->total(), ['count' => $this->proposals->total()]) }}</p>
            </div>

            @if ($this->proposals->isEmpty())
                <x-ui.empty-state icon="document-text" :heading="__('proposals.no_matching_proposals')" :body="__('common.change_the_search_or_filters_and_try_again')" />
            @else
                <div class="-mx-3 mt-2">
                    @foreach ($this->proposals as $proposal)
                        @php
                            $flowKey = 'p'.$proposal->id;
                            $isRejected = $proposal->status === ProposalStatus::Rejected;
                            // A rejection answers a submission, so the rail stops at Submitted and marks the next stop.
                            $reachedStep = (int) array_search($isRejected ? ProposalStatus::Submitted : $proposal->status, $lifecycle, true);
                            $nextStatus = $proposal->status->next();
                            $latestNote = $proposal->statusNotes->first();
                            $taskSlots = min($proposal->tasks_count, 24);
                            $doneSlots = $proposal->tasks_count > 0 ? (int) round($proposal->done_tasks_count / $proposal->tasks_count * $taskSlots) : 0;
                        @endphp
                        <article
                            id="proposal-{{ $proposal->id }}"
                            wire:key="proposal-{{ $proposal->id }}"
                            x-on:mouseenter="$store.proposalFlow.focus = '{{ $flowKey }}'"
                            x-on:mouseleave="$store.proposalFlow.focus = null"
                            :class="$store.proposalFlow.isLit('{{ $flowKey }}') ? 'bg-board-head dark:bg-white/[0.04]' : ($store.proposalFlow.isRowDimmed('{{ $flowKey }}') ? 'opacity-45' : '')"
                            class="logframe-reveal relative scroll-mt-24 rounded-xl px-3 py-7 transition-[background-color,opacity] duration-200 not-first:before:absolute not-first:before:inset-x-3 not-first:before:top-0 not-first:before:h-px not-first:before:bg-secondary-soft target:bg-board-head dark:not-first:before:bg-secondary-medium"
                            style="--logframe-order: {{ $loop->index }}"
                            data-proposal-row
                        >
                            {{-- Identity and actions --}}
                            <div class="flex flex-col gap-3 @3xl/directory:flex-row @3xl/directory:items-start">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                                        @if (in_array($proposal->id, $this->unreadIds, true))
                                            <span class="size-2 shrink-0 rounded-full bg-primary" data-unread-dot="proposal-{{ $proposal->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                        @endif
                                        <span class="text-lg leading-tight font-bold {{ $inkText }}">{{ $proposal->name }}</span>
                                        <x-ui.enum-badge :case="$proposal->status" />
                                        {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                                        <span class="text-xs font-semibold {{ $mutedText }}">{{ $proposal->phase->label() }}</span>
                                    </div>
                                    <p class="mt-1 text-xs tabular-nums {{ $mutedText }}">
                                        {{ __('proposals.planned_range', ['begin' => $proposal->planned_date_begin?->translatedFormat(__('formats.date')), 'end' => $proposal->planned_date_end?->translatedFormat(__('formats.date'))]) }}
                                        @if ($proposal->actual_date_begin !== null)
                                            <span aria-hidden="true">·</span>
                                            {{ __('proposals.actual_begin', ['begin' => $proposal->actual_date_begin->translatedFormat(__('formats.date'))]) }}
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-1">
                                    <x-ui.button tone="ghost" size="sm" icon="eye" class="size-10! touch-manipulation" :href="route('proposals.show', $proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $proposal->name])" />
                                    @can('update', $proposal)
                                    @if ($nextStatus !== null)
                                        <x-ui.button size="sm" icon:trailing="arrow-right" class="min-h-10 touch-manipulation rtl:[&_svg]:rotate-180" wire:click="openStageModal({{ $proposal->id }})" data-test="advance-proposal-{{ $proposal->id }}">
                                            {{ __('proposals.move_to_status', ['status' => $nextStatus->label()]) }}
                                        </x-ui.button>
                                    @else
                                        <x-ui.button size="sm" tone="ghost" icon="arrows-right-left" class="min-h-10 touch-manipulation" wire:click="openStageModal({{ $proposal->id }})" data-test="advance-proposal-{{ $proposal->id }}">
                                            {{ __('proposals.change_status') }}
                                        </x-ui.button>
                                    @endif
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-10! touch-manipulation" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />
                                    @endcan
                                    @can('delete', $proposal)
                                    @if ($proposal->donorProposals->isEmpty())
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-10! touch-manipulation" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                                    @else
                                        <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                            <span class="inline-flex size-10 cursor-not-allowed items-center justify-center rounded-lg text-secondary-light focus-visible:outline-2 focus-visible:outline-primary" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                    @endcan
                                </div>
                            </div>

                            {{-- Lifecycle stepper --}}
                            <ol class="mt-5 grid grid-cols-7" aria-label="{{ __('proposals.lifecycle') }}">
                                @foreach ($lifecycle as $index => $step)
                                    @php
                                        $isCurrent = ! $isRejected && $index === $reachedStep;
                                        $isPast = $index < $reachedStep || ($isRejected && $index === $reachedStep);
                                        $isRejectionPoint = $isRejected && $index === $reachedStep + 1;
                                        $isAhead = ! $isPast && ! $isCurrent && ! $isRejectionPoint;
                                    @endphp
                                    <li class="relative flex flex-col items-center gap-1.5" @if ($isCurrent || $isRejectionPoint) aria-current="step" @endif>
                                        @if (! $loop->first)
                                            <span @class([
                                                'absolute end-1/2 top-[0.3125rem] h-0.5 w-full -translate-y-1/2',
                                                'bg-secondary-dark dark:bg-white' => $isPast || $isCurrent,
                                                'bg-primary' => $isRejectionPoint,
                                                'bg-secondary-soft dark:bg-secondary-medium' => $isAhead,
                                            ]) aria-hidden="true"></span>
                                        @endif
                                        <span @class([
                                            'relative z-10 size-2.5 rounded-full',
                                            'bg-primary ring-4 ring-primary/20' => $isCurrent || $isRejectionPoint,
                                            'bg-secondary-dark dark:bg-white' => $isPast,
                                            'border-2 border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark' => $isAhead,
                                        ]) aria-hidden="true"></span>
                                        <span @class([
                                            'px-0.5 text-center text-[11px] leading-tight',
                                            'font-bold text-primary' => $isCurrent || $isRejectionPoint,
                                            'hidden font-medium @4xl/directory:block '.$inkText => $isPast,
                                            'hidden font-medium text-secondary-light @4xl/directory:block' => $isAhead,
                                        ])>{{ $isRejectionPoint ? ProposalStatus::Rejected->label() : $step->label() }}</span>
                                    </li>
                                @endforeach
                            </ol>

                            {{-- Connections --}}
                            <div class="mt-6 grid gap-x-10 gap-y-6 @4xl/directory:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                {{-- Funding --}}
                                <div class="min-w-0" data-connection="funding">
                                    <p class="type-label {{ $mutedText }}">{{ __('common.funding') }}</p>
                                    @forelse ($proposal->donorProposals as $agreement)
                                        @php
                                            $money = $this->agreementMoney($agreement);
                                            $budget = (float) $agreement->budget;
                                        @endphp
                                        <div class="mt-2 [&+&]:mt-4" wire:key="agreement-{{ $agreement->id }}">
                                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                                <p class="min-w-0 text-sm">
                                                    <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->donor->name }}</a>
                                                    <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="ms-1.5 text-xs underline-offset-4 hover:underline {{ $mutedText }}">{{ filled($agreement->project_num) ? __('proposals.agreement_number', ['number' => $agreement->project_num]) : __('proposals.view_agreement') }}</a>
                                                </p>
                                                <p class="text-xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $amount($budget) }} <span class="text-xs font-semibold tracking-normal {{ $mutedText }}">{{ $agreement->currency->value }}</span></p>
                                            </div>
                                            <div class="mt-2 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $agreement->currency->value, 'received' => $amount($money['received']), 'pending' => $amount($money['pending']), 'committed' => $amount($budget)]) }}">
                                                <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $budget > 0 ? min(100, round($money['received'] / $budget * 100, 2)) : 0 }}%"></span>
                                                <span class="money-grow money-pending h-full" style="width: {{ $budget > 0 ? min(100, round($money['pending'] / $budget * 100, 2)) : 0 }}%"></span>
                                            </div>
                                            <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                                <span><span class="font-bold {{ $receivedText }}">{{ $amount($money['received']) }}</span> {{ __('proposals.received_percent', ['percent' => App\Models\DonorProposal::percentLabel($money['received'], $budget)]) }}</span>
                                                <span><span class="font-bold text-status-warn">{{ $amount($money['pending']) }}</span> {{ __('proposals.pending_lower') }}</span>
                                                @if ($money['nextDue'] !== null)
                                                    <span @class(['font-semibold text-primary' => $money['nextDue']->lt(today())])>{{ __('proposals.next_transfer_on', ['date' => $money['nextDue']->translatedFormat(__('formats.date'))]) }}</span>
                                                @endif
                                                @foreach ($money['otherCurrencies'] as $otherCurrency => $otherAmount)
                                                    <span>{{ __('proposals.also_received_in', ['amount' => $amount($otherAmount), 'currency' => $otherCurrency]) }}</span>
                                                @endforeach
                                            </p>
                                        </div>
                                    @empty
                                        <p class="mt-2 text-sm {{ $mutedText }}">{{ __('proposals.no_funding_agreements') }}</p>
                                    @endforelse
                                </div>

                                {{-- Projects --}}
                                <div class="min-w-0" data-connection="projects">
                                    <p class="type-label {{ $mutedText }}">{{ __('common.projects') }} <span class="tabular-nums">· {{ $proposal->projects->count() }}</span></p>
                                    @if ($proposal->projects->isEmpty())
                                        <p class="mt-2 text-sm {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</p>
                                    @else
                                        <ul class="relative mt-1 flex flex-col" role="list">
                                            <span class="absolute start-[0.1875rem] top-4 bottom-4 w-px bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                                            @foreach ($proposal->projects as $project)
                                                <li class="relative flex min-h-9 items-center gap-x-2.5 gap-y-0.5 py-1 ps-5" wire:key="proposal-{{ $proposal->id }}-project-{{ $project->id }}">
                                                    <span class="absolute start-0 size-2 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="min-w-0 text-sm leading-snug font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $project->name }}</a>
                                                    <span class="shrink-0 text-[11px] {{ $mutedText }}">{{ $project->status->label() }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>

                                {{-- Delivery --}}
                                <div class="min-w-0" data-connection="delivery">
                                    <p class="type-label {{ $mutedText }}">{{ __('proposals.delivery') }}</p>
                                    <dl class="mt-2 flex flex-col gap-3.5">
                                        <div>
                                            <dt class="sr-only">{{ __('common.tasks') }}</dt>
                                            <dd>
                                                <p class="text-sm {{ $inkText }}">
                                                    @if ($proposal->tasks_count === 0)
                                                        <span class="{{ $mutedText }}">{{ __('proposals.no_tasks_yet') }}</span>
                                                    @else
                                                        {{ __('proposals.tasks_done_of', ['done' => $proposal->done_tasks_count, 'total' => $proposal->tasks_count]) }}
                                                        @if ($proposal->overdue_tasks_count > 0)
                                                            <span class="ms-1 font-bold text-primary">{{ trans_choice('proposals.count_overdue', $proposal->overdue_tasks_count, ['count' => $proposal->overdue_tasks_count]) }}</span>
                                                        @endif
                                                    @endif
                                                </p>
                                                @if ($taskSlots > 0)
                                                    <div class="mt-2 flex h-1.5 w-full gap-0.5" aria-hidden="true">
                                                        @for ($slot = 0; $slot < $taskSlots; $slot++)
                                                            <span @class(['h-full flex-1 rounded-full', 'bg-status-good' => $slot < $doneSlots, 'bg-secondary-soft dark:bg-secondary-medium' => $slot >= $doneSlots])></span>
                                                        @endfor
                                                    </div>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                                            <dt class="{{ $mutedText }}">{{ __('common.targets') }}</dt>
                                            <dd class="me-3 font-bold tabular-nums {{ $inkText }}">{{ $proposal->plan_indicators_count }}</dd>
                                            <dt class="{{ $mutedText }}">{{ __('proposals.status_changes') }}</dt>
                                            <dd class="font-bold tabular-nums {{ $inkText }}">{{ $proposal->status_notes_count }}</dd>
                                        </div>
                                        @if ($latestNote !== null)
                                            <div class="relative ps-5">
                                                <span class="absolute start-0 top-1 size-2 rounded-full bg-secondary-light" aria-hidden="true"></span>
                                                <dt class="text-xs {{ $mutedText }}">{{ __('proposals.latest_note_on', ['date' => $latestNote->date?->translatedFormat(__('formats.date')), 'status' => $latestNote->status->label()]) }}</dt>
                                                <dd class="mt-0.5 line-clamp-2 text-sm {{ $inkText }}">{{ $latestNote->context }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="mt-2 border-t pt-4 {{ $hairline }}">
                <flux:pagination :paginator="$this->proposals" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#proposal-pipeline-heading" />
            </div>
        </section>
    @endif

    <x-ui.modal
        wire:model="showProposalModal"
        size="xl"
        :title="$editingProposalId === null ? __('proposals.add_proposal') : __('proposals.edit_proposal')"
        :description="__('proposals.phase_follows_automatically_from_the_status_you_choose')"
        submit="saveProposal"
        close="closeProposalModal"
        required-note
    >
        <x-ui.form-section
            :title="__('common.section_details')"
            :hint="$editingProposalId === null ? __('proposals.the_starting_status_opens_the_status_history') : __('proposals.status_moves_only_through_change_status')"
            :fields="['name', 'proposalStatus', 'notes']"
        >
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="name" :label="__('proposals.proposal_name')" maxlength="255" required autofocus :class="$editingProposalId !== null ? '@2xl/modal:col-span-2' : null" />

                @if ($editingProposalId === null)
                    <x-ui.form-select wire:model="proposalStatus" :label="__('proposals.starting_status')" required>
                        @foreach (ProposalStatus::cases() as $statusCase)
                            <flux:select.option :value="$statusCase->value">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>
                @endif

                <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" class="@2xl/modal:col-span-2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedDateBegin', 'plannedDateEnd', 'actualDateBegin', 'actualDateEnd']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                <x-ui.form-input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.form-input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
                <x-ui.form-input wire:model="actualDateBegin" :label="__('proposals.actual_start')" type="date" />
                <x-ui.form-input wire:model="actualDateEnd" :label="__('proposals.actual_end')" type="date" />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-proposal-button">
                {{ $editingProposalId === null ? __('proposals.create_proposal') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showStageModal"
        size="md"
        :title="__('proposals.change_status')"
        :description="__('proposals.the_new_status_and_your_reason_are_recorded_together_as_one')"
        submit="recordStageChange"
        close="closeStageModal"
        required-note
    >
        <div class="grid grid-cols-2 gap-x-4 gap-y-4">
            <x-ui.form-select wire:model="newStatus" :label="__('proposals.new_status')" required autofocus>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</flux:select.option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" required />

            <x-ui.form-textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" required class="col-span-2" />
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-stage-button">{{ __('proposals.record_change') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('proposals.delete_this_proposal')"
        :message="__('proposals.its_tasks_project_links_and_status_history_are_removed_with')"
        confirm="deleteProposal"
        :confirm-label="__('proposals.delete_proposal')"
        confirm-test="confirm-delete-proposal"
    />
</section>
