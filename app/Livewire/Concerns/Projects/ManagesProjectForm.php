<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Locations\SaveLocationDetails;
use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The edit dialog of a place: its form properties, opening, saving and
 * closing it.
 *
 * Y-17: a place is a **location row** of a proposal, and this form changes
 * only what the place delivers — its description, the five beneficiary
 * targets and its sectors (`SaveLocationDetails`, contracts.md §3). Three
 * things it used to do and no longer does:
 *
 * - **create a project.** A place exists because a proposal has it (D-84,
 *   D-87), so the proposal form is the only way in and
 *   `ProposalLocationPolicy::create()` is false. With it went the one-open-
 *   project check (rule L94 is withdrawn, D-77), the "similar projects"
 *   confirmation and the default-name helper.
 * - **name it.** A place is named by where it is (`ProposalLocation::label()`).
 * - **place it, own it or date it.** City, program, centers and responsible
 *   are decided on the proposal — `SaveProposal` before approval, a change
 *   request afterwards (Y-09) — and the period comes from the approved grant
 *   (`ApproveLocationFromProposal`).
 *
 * Used by projects/index and projects/show, which provide WithPagination's
 * resetPage().
 */
trait ManagesProjectForm
{
    public bool $showProjectModal = false;

    #[Locked]
    public ?int $editingProjectId = null;

    public string $description = '';

    public string $targetBeneficiaries = '';

    public string $targetMen = '';

    public string $targetWomen = '';

    public string $targetBoys = '';

    public string $targetGirls = '';

    /**
     * The sectors the place covers (contracts.md §12.5, D-47), as strings
     * because they come from checkbox input.
     *
     * @var list<string>
     */
    public array $sectorIds = [];

    /**
     * The status the row held when the form opened, so the dialog can say
     * where its dates come from without re-reading the row.
     */
    #[Locked]
    public ?string $savedStatus = null;

    /**
     * Where the place is, shown read-only at the top of the dialog.
     */
    #[Locked]
    public string $editingPlaceLabel = '';

    /**
     * Whether the place holds a period at all. It is never editable here —
     * the grant sets it — so this only picks which sentence the dialog
     * shows.
     */
    public function datesEditable(): bool
    {
        return $this->savedStatus !== null && $this->savedStatus !== ProjectStatus::Draft->value;
    }

    /**
     * Open the form on a location row, reached by its own key, which also
     * answers for the permission.
     */
    public function openEditModal(int $locationId): void
    {
        $row = ProposalLocation::query()->with(['city', 'program', 'centers'])->findOrFail($locationId);

        Gate::authorize('update', $row);

        $this->resetValidation();
        $this->editingProjectId = $row->id;
        $this->editingPlaceLabel = $row->label();
        $this->savedStatus = $row->status->value;
        $this->description = $row->description ?? '';
        $this->targetBeneficiaries = self::formInteger($row->target_beneficiaries);
        $this->targetMen = self::formInteger($row->target_men);
        $this->targetWomen = self::formInteger($row->target_women);
        $this->targetBoys = self::formInteger($row->target_boys);
        $this->targetGirls = self::formInteger($row->target_girls);
        $this->sectorIds = array_values($row->sectors()->ordered()->pluck('sectors.id')->map(fn (mixed $id): string => (string) $id)->all());
        $this->showProjectModal = true;
    }

    public function saveProject(): void
    {
        if ($this->editingProjectId === null) {
            return;
        }

        $row = ProposalLocation::query()->findOrFail($this->editingProjectId);

        app(SaveLocationDetails::class)->handle($row, [
            'description' => $this->description,
            'targetBeneficiaries' => $this->targetBeneficiaries,
            'targetMen' => $this->targetMen,
            'targetWomen' => $this->targetWomen,
            'targetBoys' => $this->targetBoys,
            'targetGirls' => $this->targetGirls,
            'sectorIds' => $this->sectorIds,
        ], auth()->user());

        $this->showProjectModal = false;
        $this->resetProjectForm();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('projects.project_updated'));
    }

    #[On('notification-received')]
    public function refreshFromInbox(): void {}

    public function closeProjectModal(): void
    {
        $this->showProjectModal = false;
        $this->resetProjectForm();
    }

    protected function resetProjectForm(): void
    {
        $this->resetValidation();
        $this->editingProjectId = null;
        $this->editingPlaceLabel = '';
        $this->savedStatus = null;
        $this->description = '';
        $this->targetBeneficiaries = '';
        $this->targetMen = '';
        $this->targetWomen = '';
        $this->targetBoys = '';
        $this->targetGirls = '';
        $this->sectorIds = [];
    }

    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected static function formInteger(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
