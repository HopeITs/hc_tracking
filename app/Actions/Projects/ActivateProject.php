<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProjectActivated;
use App\Events\ProposalStatusChanged;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Activate an approved, dated project and move its still-approved proposals to
 * active (contracts.md §3, §6; D-04b, D-39).
 *
 * The one Team A entry point for `approved → active`: T33's
 * ConfirmReportTemplate calls it inside its own transaction. A project that is
 * not `approved` (including one already active) is refused with no write, so a
 * second call raises no second event. Events fire after the commit.
 */
final class ActivateProject
{
    /** @var list<string> */
    public const VIAS = ['template_confirmation'];

    public function handle(Project $project, string $via): void
    {
        if (! in_array($via, self::VIAS, true)) {
            throw new InvalidArgumentException("Unknown activation path [{$via}].");
        }

        DB::transaction(function () use ($project, $via): void {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('activate', $locked);

            if ($locked->status !== ProjectStatus::Approved) {
                throw ValidationException::withMessages(['status' => __('projects.activate_not_approved')]);
            }

            if ($locked->start_date === null || $locked->end_date === null) {
                throw ValidationException::withMessages(['status' => __('projects.activate_dates_required')]);
            }

            if (! $locked->isAssignmentAcknowledged('user_id')) {
                throw ValidationException::withMessages(['status' => __('projects.activate_owner_not_acknowledged')]);
            }

            $proposals = $this->lockApprovedProposals($locked);

            $locked->status = ProjectStatus::Active;
            $locked->setActivityContext(['via' => $via, 'proposal_id' => $proposals[0]->id ?? null]);
            $locked->save();

            foreach ($proposals as $proposal) {
                $proposal->status = ProposalStatus::Active;
                $proposal->save();

                ProposalStatusNote::query()->create([
                    'proposal_id' => $proposal->id,
                    'status' => ProposalStatus::Active,
                    'context' => __('proposals.activated_with_report_template'),
                    'date' => today()->toDateString(),
                    'changed_by' => Auth::id(),
                    'is_correction' => false,
                ]);

                ProposalStatusChanged::dispatch($proposal->id, ProposalStatus::Approved, ProposalStatus::Active, Auth::user()?->id);
            }

            ProjectActivated::dispatch(
                $locked->id,
                $proposals[0]->id ?? null,
                CarbonImmutable::parse($locked->start_date->toDateString()),
                CarbonImmutable::parse($locked->end_date->toDateString()),
                $via,
            );
        });

        $project->refresh();
    }

    /**
     * The project's linked proposals still at `approved`, earliest grant first,
     * locked in ascending id.
     *
     * @return list<Proposal>
     */
    private function lockApprovedProposals(Project $project): array
    {
        $ids = $project->proposals()->where('proposals.status', ProposalStatus::Approved)->orderBy('proposals.id')->pluck('proposals.id')->all();

        if ($ids === []) {
            return [];
        }

        $proposals = Proposal::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->all();

        usort($proposals, fn (Proposal $a, Proposal $b): int => [$a->actual_date_begin?->toDateString() ?? '', $a->id] <=> [$b->actual_date_begin?->toDateString() ?? '', $b->id]);

        return $proposals;
    }
}
