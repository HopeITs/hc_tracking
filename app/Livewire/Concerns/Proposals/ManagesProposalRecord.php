<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\ChangeProposalStatus;
use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\UnlinkProposalProject;
use App\Enums\ProposalStatus;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The proposal's own record on proposals/show: the status modal (a move
 * always writes its audit note) and linking or unlinking the projects it
 * funds. Edit details is the shared proposal form (ManagesProposalForm).
 *
 * Used by proposals/show only. The page provides proposalId, the
 * #[Computed] proposal() and forgetReadings(); projects comes from
 * ReadsProposalDelivery.
 *
 * @property int $proposalId
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 */
trait ManagesProposalRecord
{
    public bool $showStatusModal = false;

    public string $newStatus = '';

    public string $statusContext = '';

    public string $statusDate = '';

    public string $projectToLink = '';

    public bool $showUnlinkModal = false;

    #[Locked]
    public ?int $unlinkingProjectId = null;

    #[Locked]
    public int $unlinkUnreportedTargetCount = 0;

    /**
     * Drop every memoised reading after a write. Provided by the page.
     */
    abstract private function forgetReadings(): void;

    public function openStatusModal(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        $this->newStatus = $this->proposal->status->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStatusModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction. The live status and its audit
     * trail are the same vocabulary — proposal_status_notes.status casts to the
     * same ProposalStatus enum — so they must never be allowed to disagree.
     */
    public function changeStatus(): void
    {
        Gate::authorize('update', $this->proposal);

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

        app(ChangeProposalStatus::class)->handle(
            Proposal::query()->findOrFail($this->proposalId),
            $validated['newStatus'],
            $validated['statusContext'],
            $validated['statusDate'],
        );

        $this->showStatusModal = false;
        $this->statusContext = '';
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }

    /**
     * Projects this proposal may still fund, offered for linking: the same
     * Project::linkableToProposals() query LinkProposalProject checks, minus
     * the projects already linked. Empty for anyone who cannot link.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function linkableProjects(): Collection
    {
        if (! Gate::allows('update', $this->proposal)) {
            return new Collection;
        }

        return Project::query()
            ->linkableToProposals()
            ->select(['id', 'name'])
            ->whereDoesntHave('proposals', fn (Builder $query) => $query->whereKey($this->proposalId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Link the chosen project through LinkProposalProject. A refusal is shown
     * under the project picker.
     */
    public function linkProject(): void
    {
        Gate::authorize('update', $this->proposal);

        $validated = $this->validate([
            'projectToLink' => ['required', 'integer'],
        ], [
            'projectToLink.required' => __('proposals.select_a_project_to_link'),
            'projectToLink.integer' => __('proposals.that_project_is_no_longer_available'),
        ]);

        try {
            app(LinkProposalProject::class)->handle(Proposal::query()->findOrFail($this->proposalId), (int) $validated['projectToLink']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['projectToLink' => $exception->validator->errors()->all()]);
        }

        $this->projectToLink = '';
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.project_linked'));
    }

    /**
     * Ask before unlinking, counting this proposal's targets on the project
     * that have no reports yet: they stay after the unlink, flagged. A
     * project with reported targets is refused by the action on confirm.
     */
    public function confirmUnlinkProject(int $projectId): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        $this->unlinkingProjectId = $this->projects->firstWhere('id', $projectId)?->id;
        $this->unlinkUnreportedTargetCount = $this->unlinkingProjectId === null ? 0 : PlanIndicator::query()
            ->where('source_type', $this->proposal->getMorphClass())
            ->where('source_id', $this->proposalId)
            ->where('project_id', $this->unlinkingProjectId)
            ->doesntHave('reports')
            ->count();
        $this->showUnlinkModal = $this->unlinkingProjectId !== null;
    }

    /**
     * Stop funding a project through UnlinkProposalProject. A refusal (the
     * last project, reported targets) keeps the dialog open with the reason.
     */
    public function unlinkProject(): void
    {
        Gate::authorize('update', $this->proposal);

        if ($this->unlinkingProjectId !== null) {
            app(UnlinkProposalProject::class)->handle(Proposal::query()->findOrFail($this->proposalId), $this->unlinkingProjectId);
            Flux::toast(variant: 'success', text: __('proposals.project_unlinked'));
        }

        $this->showUnlinkModal = false;
        $this->unlinkingProjectId = null;
        $this->unlinkUnreportedTargetCount = 0;
        $this->forgetReadings();
    }
}
