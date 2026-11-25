<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\UnlinkProjectWithTargets;
use App\Actions\Proposals\UnlinkProposalProject;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\TargetCounts;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The proposal's own record on proposals/show: linking or unlinking the
 * projects it funds. The status modals are ManagesProposalStatus and Edit
 * details is the shared proposal form (ManagesProposalForm).
 *
 * Used by proposals/show only. The page provides proposalId, the
 * #[Computed] proposal() and forgetReadings(); projects comes from
 * ReadsProposalDelivery.
 *
 * @property int $proposalId
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read bool $unlinkDeletesTargets  #[Computed] unlinkDeletesTargets()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 */
trait ManagesProposalRecord
{
    public string $projectToLink = '';

    public bool $showUnlinkModal = false;

    #[Locked]
    public ?int $unlinkingProjectId = null;

    /**
     * The targets on the location row of the project being unlinked (Y-10):
     * the dialog says how many, how many have reports, and offers to delete
     * them with the unlink to a holder of `targets.delete`.
     *
     * @var array{count: int, reported: int}
     */
    #[Locked]
    public array $unlinkTargets = ['count' => 0, 'reported' => 0];

    /**
     * Drop every memoised reading after a write. Provided by the page.
     */
    abstract private function forgetReadings(): void;

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
     * Ask before unlinking. The project's location row goes with the link
     * (Y-10), so the dialog counts the targets on that row: a holder of
     * `targets.delete` may delete them with the unlink, anyone else is sent
     * to MEAL or to the targets. A reported target is never deleted.
     */
    public function confirmUnlinkProject(int $projectId): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        // Y-16: the tab lists rows, but the unlink still names a project (Y-18
        // owns the columns). A project linked before the rows existed has no
        // row and must still be unlinkable (Y-10), so the pivot decides.
        $this->unlinkingProjectId = $this->proposal->projects()->whereKey($projectId)->value('projects.id');
        $row = $this->unlinkingProjectId === null ? null : $this->proposal->locations()->where('project_id', $this->unlinkingProjectId)->first();
        $this->unlinkTargets = $row === null ? ['count' => 0, 'reported' => 0] : TargetCounts::ofLocation($row);
        $this->showUnlinkModal = $this->unlinkingProjectId !== null;
    }

    /**
     * Whether the unlink dialog offers to delete the row's targets with it.
     */
    #[Computed]
    public function unlinkDeletesTargets(): bool
    {
        return $this->unlinkTargets['count'] > 0 && $this->unlinkTargets['reported'] === 0 && Gate::allows('targets.delete');
    }

    /**
     * Stop funding a project through UnlinkProposalProject, deleting the
     * row's targets with it when the dialog offered that. A refusal (the last
     * project, reported targets, targets nobody may delete here) keeps the
     * dialog open with the reason.
     */
    public function unlinkProject(): void
    {
        Gate::authorize('update', $this->proposal);

        if ($this->unlinkingProjectId !== null) {
            $proposal = Proposal::query()->findOrFail($this->proposalId);

            if ($this->unlinkDeletesTargets) {
                app(UnlinkProjectWithTargets::class)->handle($proposal, $this->unlinkingProjectId);
            } else {
                app(UnlinkProposalProject::class)->handle($proposal, $this->unlinkingProjectId);
            }

            Flux::toast(variant: 'success', text: __('proposals.project_unlinked'));
        }

        $this->showUnlinkModal = false;
        $this->unlinkingProjectId = null;
        $this->unlinkTargets = ['count' => 0, 'reported' => 0];
        unset($this->unlinkDeletesTargets);
        $this->forgetReadings();
    }
}
