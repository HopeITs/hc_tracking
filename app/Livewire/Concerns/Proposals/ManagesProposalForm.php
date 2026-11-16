<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\CloneProposalForRenewal;
use App\Actions\Proposals\SaveProposal;
use App\Actions\Proposals\SaveProposalFile;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\HrCenter;
use App\Models\HrProgram;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use App\Rules\SecureUpload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The proposal form v2 (T06): create with its location rows (W-34a slice 3,
 * EditsProposalLocations), or edit the details, donor, budget, rows and
 * beneficiaries. Every save goes through SaveProposal, so the form writes no
 * status and no links of its own: a new proposal starts at the concept note
 * (or skips it with a reason), and an edit leaves links to the proposal
 * page's Projects tab. The one project a create may carry is the one the
 * project page opened it from (New Concept Note).
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
    use EditsProposalLocations;

    public bool $showProposalModal = false;

    #[Locked]
    public ?int $editingProposalId = null;

    /**
     * The proposal kind (W-32): chosen on create only. On edit it holds the
     * stored kind for display and is never sent, so SaveProposal keeps it.
     */
    public string $kind = 'project';

    public string $name = '';

    public string $internalProjectNumber = '';

    public string $donorId = '';

    /**
     * The project a create was opened from (New Concept Note on the project
     * page), linked on save. There is no picker any more: places are rows.
     *
     * @var list<string>
     */
    public array $projectIds = [];

    /** @var array<int|string, int|string|null> */
    public array $beneficiaries = [];

    public string $beneficiariesReason = '';

    public string $requestedBudget = '';

    public string $requestedCurrency = '';

    public string $plannedDateBegin = '';

    public string $plannedDateEnd = '';

    public string $notes = '';

    /** The concept note itself (W-24), stored in `proposals.concept_note`. */
    public string $conceptNote = '';

    /**
     * The concept-note document (W-24), filed through SaveProposalFile under
     * MediaCollection::ConceptNote once the proposal exists. Needs the page
     * to use WithFileUploads.
     *
     * @var TemporaryUploadedFile|null
     */
    public $conceptNoteFile = null;

    /** What a payroll proposal covers (W-32, at least 50 characters). */
    public string $narrative = '';

    public string $startingStage = 'concept note';

    public string $skipReason = '';

    /**
     * The proposal being renewed (T10, "Start from previous proposal"), or ''
     * for a plain create. Chosen from renewableProposals() and read again by
     * CloneProposalForRenewal, which also refuses an invisible or
     * non-renewable one.
     */
    public string $previousProposalId = '';

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
        $keptDonorId = $this->editingProposalId === null
            ? null
            : Proposal::query()->whereKey($this->editingProposalId)->value('donor_id');

        return Donor::query()
            ->select(['id', 'name', 'approval_status'])
            ->where(fn (Builder $query) => $query
                ->pickableBy($this->proposalActor())
                ->when($keptDonorId !== null, fn (Builder $inner) => $inner->orWhere('donors.id', $keptDonorId)))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the chosen donor is a request the General Manager has not
     * approved yet (D-57): the form shows a banner and SaveProposal refuses.
     */
    #[Computed]
    public function proposalDonorIsPending(): bool
    {
        return is_numeric($this->donorId)
            && $this->proposalDonors->contains(fn (Donor $donor): bool => $donor->id === (int) $this->donorId && $donor->isPending());
    }

    /**
     * The donor request form (a nested component) saved a new donor: choose
     * it. Only a donor the user may pick is accepted, so a forged id selects
     * nothing.
     */
    #[On('donor-requested')]
    public function donorRequested(int $donorId): void
    {
        Gate::authorize('donors.create');

        if (Donor::query()->pickableBy($this->proposalActor())->whereKey($donorId)->exists()) {
            $this->donorId = (string) $donorId;
            unset($this->proposalDonors);
            $this->js("document.getElementById('proposal-donor')?.focus()");
        }
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
     * The project a create was opened from, with its beneficiaries input.
     *
     * @return list<array{id: int, name: string, place: string, hosted: ?string, centerInactive: bool}>
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
     * @return list<array{id: int, name: string, place: string, hosted: ?string, centerInactive: bool}>
     */
    #[Computed]
    public function proposalLinkedProjects(): array
    {
        if (! $this->showProposalModal || $this->editingProposalId === null) {
            return [];
        }

        $projects = Project::query()
            ->select(['projects.id', 'projects.name', 'projects.center_id', 'projects.host_center_id', 'projects.program_id', 'projects.city_id'])
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
     * The proposals a renewal can start from: visible to the user, approved,
     * active, completed or rejected, newest first (T10).
     *
     * @return list<array{id: int, label: string}>
     */
    #[Computed]
    public function renewableProposals(): array
    {
        if (! $this->showProposalModal || $this->editingProposalId !== null || ! $this->proposalActor()->can('proposals.view')) {
            return [];
        }

        return Proposal::query()
            ->select(['id', 'name', 'internal_project_number'])
            ->whereIn('status', array_map(fn (ProposalStatus $status): string => $status->value, CloneProposalForRenewal::RenewableStatuses))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'label' => $proposal->internal_project_number.' — '.$proposal->name])
            ->all();
    }

    /**
     * What choosing the previous proposal will and will not copy (T10): its
     * projects and the counts of framework nodes, milestones and tasks, plus
     * the number of a renewal draft that already exists. Counts only.
     *
     * @return array{projects: list<array{id: int, name: string, place: string, hosted: ?string, centerInactive: bool}>, results: int, milestones: int, tasks: int, existingDraft: ?string}|null
     */
    #[Computed]
    public function renewalPreview(): ?array
    {
        $previous = $this->selectedRenewable();

        if ($previous === null) {
            return null;
        }

        $projects = $previous->projects()
            ->select(['projects.id', 'projects.name', 'projects.center_id', 'projects.host_center_id', 'projects.program_id', 'projects.city_id'])
            ->with('city:id,name_en,name_ar')
            ->orderBy('projects.id')
            ->get();

        return [
            'projects' => $this->projectRows($projects),
            'results' => Result::query()->where('proposal_id', $previous->id)->count(),
            'milestones' => Milestone::query()->where('proposal_id', $previous->id)->count(),
            'tasks' => Task::query()->withoutGlobalScopes()->where('source_type', 'proposal')->where('source_id', $previous->id)->count(),
            'existingDraft' => CloneProposalForRenewal::existingDraftFor($previous)?->internal_project_number,
        ];
    }

    /**
     * Choosing (or clearing) the previous proposal pre-fills the form from it:
     * name, notes and the planned dates one year on. The internal number stays
     * blank, since the renewal needs its own.
     */
    public function updatedPreviousProposalId(): void
    {
        $this->resetValidation();
        $previous = $this->selectedRenewable();

        if ($previous === null) {
            $this->previousProposalId = '';

            return;
        }

        $span = (int) $previous->planned_date_begin->diffInDays($previous->planned_date_end);
        $begin = $previous->planned_date_end->addDay();

        $this->name = $previous->name;
        $this->notes = $previous->notes ?? '';
        $this->plannedDateBegin = $begin->toDateString();
        $this->plannedDateEnd = $begin->addDays($span)->toDateString();
    }

    /**
     * The chosen previous proposal when it is visible and renewable, else null.
     */
    private function selectedRenewable(): ?Proposal
    {
        if (! $this->showProposalModal || $this->editingProposalId !== null || ! ctype_digit($this->previousProposalId)) {
            return null;
        }

        $proposal = Proposal::query()
            ->select(['id', 'name', 'notes', 'status', 'kind', 'planned_date_begin', 'planned_date_end'])
            ->find((int) $this->previousProposalId);

        return $proposal !== null
            && in_array($proposal->status, CloneProposalForRenewal::RenewableStatuses, true)
            && $this->proposalActor()->can('view', $proposal)
                ? $proposal
                : null;
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
        } else {
            $this->addLocationRow();
        }

        $this->showProposalModal = true;
    }

    public function openEditModal(int $proposalId): void
    {
        $scope = $this->proposalFormScope();

        abort_if($scope !== null && $scope !== $proposalId, 404);

        $showsMoney = SaveProposal::canSeeMoney($this->proposalActor());
        $columns = ['id', 'name', 'donor_id', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'notes', 'concept_note', 'narrative', 'status', 'kind'];

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
        $this->conceptNote = $proposal->concept_note ?? '';
        $this->kind = $proposal->kind->value;
        $this->narrative = $proposal->narrative ?? '';

        if ($showsMoney) {
            $this->requestedBudget = $proposal->requested_budget ?? '';
            $this->requestedCurrency = $proposal->requested_currency->value ?? '';
        }

        if ($proposal->kind === ProposalKind::Project) {
            $this->loadLocationRows($proposal->id);
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

    /**
     * Choosing payroll on create drops the projects, rows, beneficiaries,
     * renewal and skip choices a project proposal had: payroll takes none of
     * them. Choosing project again starts with one empty row.
     * An edit never changes the kind, so the stored one is put back.
     */
    public function updatedKind(): void
    {
        if ($this->editingProposalId !== null) {
            $this->kind = Proposal::query()->select(['id', 'kind'])->find($this->editingProposalId)->kind->value ?? ProposalKind::Project->value;

            return;
        }

        if (ProposalKind::tryFrom($this->kind) === null) {
            $this->kind = ProposalKind::Project->value;
        }

        $this->resetValidation();

        if ($this->kind === ProposalKind::Payroll->value) {
            $this->projectIds = [];
            $this->beneficiaries = [];
            $this->previousProposalId = '';
            $this->startingStage = ProposalStatus::ConceptNote->value;
            $this->skipReason = '';
            $this->resetLocationRows();
        } elseif ($this->locations === [] && $this->projectIds === []) {
            $this->addLocationRow();
        }
    }

    /**
     * Whether the form is filling in a payroll proposal.
     */
    public function isPayrollForm(): bool
    {
        return $this->kind === ProposalKind::Payroll->value;
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

        if ($existing === null && $this->previousProposalId !== '') {
            $this->renewProposal();

            return;
        }

        $this->validateConceptNoteFile();

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

        $this->fileConceptNote($proposal);

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->proposalSaved($proposal, $existing === null);

        Flux::toast(
            variant: 'success',
            text: $existing === null ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
    }

    /**
     * Check the concept-note document (W-24) before anything is written, with
     * the Documents tab's rules, so a refusal lands on its own field and no
     * proposal is created for a file that will not be stored.
     */
    private function validateConceptNoteFile(): void
    {
        if ($this->conceptNoteFile === null) {
            return;
        }

        $allowedTypes = implode(',', FileType::values());

        $this->validate([
            'conceptNoteFile' => ['bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ]);
    }

    /**
     * File the chosen concept-note document on the saved proposal through the
     * existing upload path (SaveProposalFile), under its own collection. A
     * later upload keeps the older row, as the final report does, so the
     * ideation card can show which one is current and what came before.
     */
    private function fileConceptNote(Proposal $proposal): void
    {
        if ($this->conceptNoteFile === null) {
            return;
        }

        app(SaveProposalFile::class)->handle($proposal, null, $this->conceptNoteFile, [
            'fileCategory' => MediaCollection::ConceptNote->value,
            'fileDisplayName' => '',
        ]);
    }

    /**
     * Save a renewal through CloneProposalForRenewal (T10): the projects, donor
     * and framework come from the previous proposal, never from the form.
     */
    private function renewProposal(): void
    {
        $previous = $this->selectedRenewable();

        if ($previous === null) {
            $this->addError('previousProposalId', __('proposals.choose_previous'));

            return;
        }

        try {
            $result = app(CloneProposalForRenewal::class)->handle($previous, [
                'internalProjectNumber' => $this->internalProjectNumber,
                'name' => $this->name,
                'notes' => $this->notes === '' ? null : $this->notes,
                'plannedDateBegin' => $this->plannedDateBegin === '' ? null : $this->plannedDateBegin,
                'plannedDateEnd' => $this->plannedDateEnd === '' ? null : $this->plannedDateEnd,
            ], $this->proposalActor());
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'proposals_internal_project_number_unique')) {
                throw $exception;
            }

            $this->addError('internalProjectNumber', __('validation.unique', ['attribute' => __('validation.attributes.internalProjectNumber')]));

            return;
        }

        $notes = array_values(array_filter([
            $result['skippedProjects'] === [] ? null : __('proposals.renewal_skipped_projects', ['names' => implode('، ', $result['skippedProjects'])]),
            $result['replacedProjects'] === [] ? null : __('proposals.renewal_replaced_projects', ['names' => implode('، ', $result['replacedProjects'])]),
            $result['skippedIndicators'] === [] ? null : __('proposals.renewal_skipped_indicators', ['names' => implode('، ', $result['skippedIndicators'])]),
            $result['skippedParts'] === [] ? null : __('proposals.renewal_created_partial', ['parts' => implode('، ', array_map(fn (string $part): string => $part === 'framework' ? __('proposals.renewal_part_framework') : __('proposals.renewal_part_plan'), $result['skippedParts']))]),
        ]));

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->proposalSaved($result['proposal'], true);

        Flux::toast(
            variant: $notes === [] ? 'success' : 'warning',
            text: implode(' ', [__('proposals.renewal_created'), ...$notes]),
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
        $this->resetLocationRows();
        $this->beneficiaries = [];
        $this->beneficiariesReason = '';
        $this->requestedBudget = '';
        $this->requestedCurrency = '';
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->notes = '';
        $this->conceptNote = '';
        $this->conceptNoteFile = null;
        $this->kind = ProposalKind::Project->value;
        $this->narrative = '';
        $this->startingStage = ProposalStatus::ConceptNote->value;
        $this->skipReason = '';
        $this->previousProposalId = '';
    }

    /**
     * The form as SaveProposal's `$data`. Create sends the location rows, the
     * project it was opened from with its beneficiaries, and the starting
     * stage; edit sends the rows (once the proposal has any) and the linked
     * projects' beneficiaries. The budget is sent only by a money holder.
     *
     * A payroll proposal (W-32) sends its narrative and, on create, its kind;
     * it never sends projects, beneficiaries or a starting stage.
     *
     * @return array{name: string, internalProjectNumber: string, donorId: string|null, beneficiaries: array<int|string, int|string|null>, beneficiariesReason: string|null, plannedDateBegin: string|null, plannedDateEnd: string|null, notes: string, conceptNote: string|null, narrative?: string|null, kind?: string, projectIds?: list<string>, locations?: list<array{city_id: string, program_id: string|null, responsible_employee_id: string|null, center_ids: list<string>, is_new: bool, new_program_name_en: string, new_program_name_ar: string}>, startingStage?: string, skipReason?: string|null, requestedBudget?: string|null, requestedCurrency?: string|null}
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
            'conceptNote' => $this->conceptNote === '' ? null : $this->conceptNote,
        ];

        $isPayroll = $this->isPayrollForm();

        if ($isPayroll) {
            $data['beneficiaries'] = [];
            $data['beneficiariesReason'] = null;
            $data['narrative'] = $this->narrative === '' ? null : $this->narrative;
        }

        if ($isCreate) {
            $data['kind'] = $this->kind;
        }

        if ($isCreate && ! $isPayroll) {
            if ($this->projectIds !== []) {
                $data['projectIds'] = $this->projectIds;
            }

            // Rows are sent even when empty without a project, so the refusal names the missing place.
            if ($this->hasLocationRows() || $this->projectIds === []) {
                $data['locations'] = $this->locationPayload();
            }

            $data['startingStage'] = $this->startingStage;
            $data['skipReason'] = $this->startingStage === ProposalStatus::ProposalDraft->value ? $this->skipReason : null;
        }

        if (! $isCreate && ! $isPayroll && ($this->hasLocationRows() || $this->hadLocationRows)) {
            $data['locations'] = $this->locationPayload();
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
            ->select(['id', 'name', 'center_id', 'host_center_id', 'program_id', 'city_id'])
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
     * @return list<array{id: int, name: string, place: string, hosted: ?string, centerInactive: bool}>
     */
    private function projectRows(iterable $projects): array
    {
        $rows = [];

        foreach ($projects as $project) {
            // A hosted project (W-01) carries its host as a badge instead of a center in the place line.
            $isHosted = $project->host_center_id !== null;

            $rows[] = [
                'id' => $project->id,
                'name' => $project->name,
                'place' => implode(' · ', array_values(array_filter([
                    $isHosted ? null : $this->centerName($project->center_id),
                    $this->programName($project->program_id),
                    $project->city->name ?? __('common.unknown_city'),
                ], fn (?string $part): bool => $part !== null))),
                'hosted' => $isHosted ? $this->centerName($project->host_center_id) : null,
                'centerInactive' => $project->hasInactiveCenter(),
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
