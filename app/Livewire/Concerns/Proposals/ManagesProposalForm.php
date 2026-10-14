<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\SaveProposal;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\HrCenter;
use App\Models\HrProgram;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Like;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The proposal form v2 (T06): create from one or more projects, or edit the
 * details, donor, budget and beneficiaries. Every save goes through
 * SaveProposal, so the form writes no status and no links of its own: a new
 * proposal starts at the concept note (or skips it with a reason), and an
 * edit leaves links to the proposal page's Projects tab.
 *
 * The property names are SaveProposal's `$data` keys, so a refusal from the
 * action lands under the field that caused it.
 *
 * Used by proposals/index and proposals/show (Edit details). The page
 * implements proposalSaved(); a page pinned to one proposal overrides
 * proposalFormScope().
 */
trait ManagesProposalForm
{
    /** How many matching projects the picker lists at a time. */
    private const ProjectPickerLimit = 25;

    public bool $showProposalModal = false;

    #[Locked]
    public ?int $editingProposalId = null;

    public string $name = '';

    public string $internalProjectNumber = '';

    public string $donorId = '';

    /** @var list<string> */
    public array $projectIds = [];

    public string $projectSearch = '';

    /** @var array<int|string, int|string|null> */
    public array $beneficiaries = [];

    public string $beneficiariesReason = '';

    public string $requestedBudget = '';

    public string $requestedCurrency = '';

    public string $plannedDateBegin = '';

    public string $plannedDateEnd = '';

    public string $notes = '';

    public string $startingStage = 'concept note';

    public string $skipReason = '';

    /**
     * Called after a proposal is created or edited through the form.
     */
    abstract protected function proposalSaved(Proposal $proposal, bool $wasCreated): void;

    /**
     * The proposal this page's form may edit, or null when it may edit any.
     */
    protected function proposalFormScope(): ?int
    {
        return null;
    }

    /**
     * The donors a proposal can be assigned to.
     *
     * @return Collection<int, Donor>
     */
    #[Computed]
    public function proposalDonors(): Collection
    {
        return Donor::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether the acting user may see and set the budget (contracts.md §8).
     */
    #[Computed]
    public function proposalFormShowsMoney(): bool
    {
        return SaveProposal::canSeeMoney($this->proposalActor());
    }

    /**
     * The picker's rows while creating: linkable projects matching the
     * search, at most ProjectPickerLimit of them, plus the ones already
     * chosen so a narrowed search never hides a choice.
     *
     * @return list<array{id: int, name: string, place: string}>
     */
    #[Computed]
    public function proposalPickerProjects(): array
    {
        if (! $this->showProposalModal || $this->editingProposalId !== null || ! $this->proposalActor()->can('projects.view')) {
            return [];
        }

        $search = trim($this->projectSearch);

        $matches = $this->linkableProjectQuery()
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
            ->limit(self::ProjectPickerLimit)
            ->get();

        return $this->projectRows($matches->merge($this->chosenProjects())->sortBy([['name', 'asc'], ['id', 'asc']]));
    }

    /**
     * The chosen projects while creating, each with its beneficiaries input.
     *
     * @return list<array{id: int, name: string, place: string}>
     */
    #[Computed]
    public function proposalChosenProjects(): array
    {
        if (! $this->showProposalModal || $this->editingProposalId !== null) {
            return [];
        }

        return $this->projectRows($this->chosenProjects());
    }

    /**
     * The projects already linked to the proposal being edited, each with its
     * beneficiaries input. Links themselves change on the proposal page.
     *
     * @return list<array{id: int, name: string, place: string}>
     */
    #[Computed]
    public function proposalLinkedProjects(): array
    {
        if (! $this->showProposalModal || $this->editingProposalId === null) {
            return [];
        }

        $projects = Project::query()
            ->select(['projects.id', 'projects.name', 'projects.center_id', 'projects.program_id', 'projects.city_id'])
            ->whereHas('proposals', fn (Builder $query) => $query->whereKey($this->editingProposalId))
            ->with('city:id,name_en,name_ar')
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->get();

        return $this->projectRows($projects);
    }

    /**
     * What the edit form must lock or ask for: the donor once the concept
     * note has left the house or an agreement names it, and a reason for a
     * beneficiary change once the donor approved.
     *
     * @return array{donorLocked: bool, approved: bool}
     */
    #[Computed]
    public function proposalEditFacts(): array
    {
        if (! $this->showProposalModal || $this->editingProposalId === null) {
            return ['donorLocked' => false, 'approved' => false];
        }

        $proposal = Proposal::query()->select(['id', 'status'])->withExists('donorProposals')->findOrFail($this->editingProposalId);
        $isOpen = in_array($proposal->status, [ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview], true);

        return [
            'donorLocked' => ! $isOpen || (bool) $proposal->getAttribute('donor_proposals_exists'),
            'approved' => $proposal->status->isApprovedByDonor(),
        ];
    }

    /**
     * Open an empty create form. A project id (New Concept Note on the
     * project page) is pre-selected only when it may take a proposal; the
     * save checks it again.
     */
    public function openCreateModal(?int $projectId = null): void
    {
        Gate::authorize('create', Proposal::class);

        $this->resetProposalForm();

        if ($projectId !== null && $this->proposalActor()->can('projects.view') && $this->linkableProjectQuery()->whereKey($projectId)->exists()) {
            $this->projectIds = [(string) $projectId];
        }

        $this->showProposalModal = true;
    }

    public function openEditModal(int $proposalId): void
    {
        $scope = $this->proposalFormScope();

        abort_if($scope !== null && $scope !== $proposalId, 404);

        $showsMoney = SaveProposal::canSeeMoney($this->proposalActor());
        $columns = ['id', 'name', 'donor_id', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'notes', 'status'];

        // The budget is read only for someone who may see money (contracts.md §8).
        $proposal = Proposal::query()
            ->select($showsMoney ? [...$columns, 'requested_budget', 'requested_currency'] : $columns)
            ->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetProposalForm();
        $this->editingProposalId = $proposal->id;
        $this->name = $proposal->name;
        $this->internalProjectNumber = $proposal->internal_project_number;
        $this->donorId = (string) $proposal->donor_id;
        $this->plannedDateBegin = $proposal->planned_date_begin->toDateString();
        $this->plannedDateEnd = $proposal->planned_date_end->toDateString();
        $this->notes = $proposal->notes ?? '';

        if ($showsMoney) {
            $this->requestedBudget = $proposal->requested_budget ?? '';
            $this->requestedCurrency = $proposal->requested_currency->value ?? '';
        }

        foreach ($proposal->projects()->select(['projects.id'])->get() as $project) {
            /** @var int|string|null $stored */
            $stored = $project->getRelation('pivot')->getAttribute('target_beneficiaries');
            $this->beneficiaries[$project->id] = $stored === null ? '' : (string) $stored;
        }

        $this->showProposalModal = true;
    }

    /**
     * Keep the chosen ids distinct and drop the beneficiaries of a project
     * that was unticked, so the save never sends a stale key.
     */
    public function updatedProjectIds(): void
    {
        $this->projectIds = array_values(array_unique(array_map('strval', array_filter($this->projectIds, 'is_scalar'))));
        $this->beneficiaries = array_intersect_key($this->beneficiaries, array_flip($this->projectIds));
    }

    public function removeChosenProject(int $projectId): void
    {
        $this->projectIds = array_values(array_filter($this->projectIds, fn (string $id): bool => $id !== (string) $projectId));
        unset($this->beneficiaries[$projectId]);
    }

    /**
     * Fill the skip reason with the standard one (D-03).
     */
    public function useSkipPreset(): void
    {
        $this->skipReason = __('proposals.skip_reason_preset');
    }

    public function saveProposal(): void
    {
        $existing = null;

        if ($this->editingProposalId !== null) {
            $scope = $this->proposalFormScope();

            abort_if($scope !== null && $scope !== $this->editingProposalId, 404);

            $existing = Proposal::query()->findOrFail($this->editingProposalId);
        }

        $this->resetValidation();

        try {
            $proposal = app(SaveProposal::class)->handle($existing, $this->proposalFormData($existing === null), $this->proposalActor());
        } catch (UniqueConstraintViolationException $exception) {
            // Two people saved the same new number at the same moment: both
            // passed validation and the unique index refused the second.
            if (! str_contains($exception->getMessage(), 'proposals_internal_project_number_unique')) {
                throw $exception;
            }

            $this->addError('internalProjectNumber', __('validation.unique', ['attribute' => __('validation.attributes.internalProjectNumber')]));

            return;
        }

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->proposalSaved($proposal, $existing === null);

        Flux::toast(
            variant: 'success',
            text: $existing === null ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
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
        $this->internalProjectNumber = '';
        $this->donorId = '';
        $this->projectIds = [];
        $this->projectSearch = '';
        $this->beneficiaries = [];
        $this->beneficiariesReason = '';
        $this->requestedBudget = '';
        $this->requestedCurrency = '';
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->notes = '';
        $this->startingStage = ProposalStatus::ConceptNote->value;
        $this->skipReason = '';
    }

    /**
     * The form as SaveProposal's `$data`. Create sends the chosen projects,
     * their beneficiaries and the starting stage; edit sends the linked
     * projects' beneficiaries. The budget is sent only by a money holder.
     *
     * @return array{name: string, internalProjectNumber: string, donorId: string|null, beneficiaries: array<int|string, int|string|null>, beneficiariesReason: string|null, plannedDateBegin: string|null, plannedDateEnd: string|null, notes: string, projectIds?: list<string>, startingStage?: string, skipReason?: string|null, requestedBudget?: string|null, requestedCurrency?: string|null}
     */
    private function proposalFormData(bool $isCreate): array
    {
        $beneficiaries = $isCreate
            ? array_intersect_key($this->beneficiaries, array_flip($this->projectIds))
            : $this->beneficiaries;

        $data = [
            'name' => $this->name,
            'internalProjectNumber' => $this->internalProjectNumber,
            'donorId' => $this->donorId === '' ? null : $this->donorId,
            'beneficiaries' => array_map(fn (int|string|null $count): int|string|null => $count === '' ? null : $count, $beneficiaries),
            'beneficiariesReason' => $this->beneficiariesReason === '' ? null : $this->beneficiariesReason,
            'plannedDateBegin' => $this->plannedDateBegin === '' ? null : $this->plannedDateBegin,
            'plannedDateEnd' => $this->plannedDateEnd === '' ? null : $this->plannedDateEnd,
            'notes' => $this->notes,
        ];

        if ($isCreate) {
            $data['projectIds'] = $this->projectIds;
            $data['startingStage'] = $this->startingStage;
            $data['skipReason'] = $this->startingStage === ProposalStatus::ProposalDraft->value ? $this->skipReason : null;
        }

        if (SaveProposal::canSeeMoney($this->proposalActor())) {
            $data['requestedBudget'] = $this->requestedBudget === '' ? null : $this->requestedBudget;
            $data['requestedCurrency'] = $this->requestedCurrency === '' ? null : $this->requestedCurrency;
        }

        return $data;
    }

    /**
     * Projects a proposal may be linked to (Project::linkableToProposals()),
     * with only what the picker shows.
     *
     * @return Builder<Project>
     */
    private function linkableProjectQuery(): Builder
    {
        return Project::query()
            ->linkableToProposals()
            ->select(['id', 'name', 'center_id', 'program_id', 'city_id'])
            ->with('city:id,name_en,name_ar')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * The chosen projects that are still linkable. One that is no longer
     * linkable drops out of the list; the save refuses it by name.
     *
     * @return Collection<int, Project>
     */
    private function chosenProjects(): Collection
    {
        $ids = array_map('intval', array_filter($this->projectIds, 'ctype_digit'));

        if ($ids === [] || ! $this->proposalActor()->can('projects.view')) {
            return new Collection;
        }

        return $this->linkableProjectQuery()->whereKey($ids)->get();
    }

    /**
     * Each project as a picker row: its name and where it runs, the HR
     * center, the program and the city. A center or program HR cannot answer
     * for shows its id.
     *
     * @param  iterable<Project>  $projects
     * @return list<array{id: int, name: string, place: string}>
     */
    private function projectRows(iterable $projects): array
    {
        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [
                'id' => $project->id,
                'name' => $project->name,
                'place' => implode(' · ', [
                    $this->centerName($project->center_id),
                    $this->programName($project->program_id),
                    $project->city->name ?? __('common.unknown_city'),
                ]),
            ];
        }

        return $rows;
    }

    private function centerName(?int $centerId): string
    {
        if ($centerId === null) {
            return __('proposals.no_center');
        }

        try {
            return HrCenter::remembered($centerId)->name ?? '#'.$centerId;
        } catch (QueryException) {
            return '#'.$centerId;
        }
    }

    private function programName(int $programId): string
    {
        try {
            $name = HrProgram::nameOf($programId);
        } catch (QueryException) {
            $name = '';
        }

        return $name !== '' ? $name : '#'.$programId;
    }

    private function proposalActor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
