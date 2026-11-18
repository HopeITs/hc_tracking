<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where a project stands on its seven stages and how healthy it is, shared
 * by the Desk's Projects panel and the project Story.
 *
 * The stage comes from the project's leading proposal and its own status
 * (the artifact's stageOf()); 7 means closed or archived. Health is derived
 * (user decision, .ai/rules/desk.md): off track when something is late (a
 * past month's monthly report still open, a late transfer, or an active project already past its end date, the projects
 * register's "Overdue"), needs a look when something falls due within seven days or a
 * report waits for MEAL, otherwise on track. Closed projects are on track.
 */
final class ProjectStage
{
    /**
     * Which proposal leads a project, most advanced first (artifact PRIORITY_ORDER).
     */
    private const array Priority = ['active', 'final reports', 'approved', 'submitted', 'on hold', 'proposal review', 'proposal draft', 'concept note submitted', 'concept note review', 'concept note', 'completed', 'rejected'];

    /**
     * @param  Collection<int, Proposal>  $proposals
     */
    public static function lead(Collection $proposals): ?Proposal
    {
        return $proposals->sortBy(fn (Proposal $proposal): int => (int) array_search($proposal->status->value, self::Priority, true))->first();
    }

    public static function stage(Project $project, ?Proposal $lead): int
    {
        if (in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true)) {
            return 7;
        }

        return match ($lead?->status) {
            null => 0,
            ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNoteSubmitted => 1,
            ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview => 2,
            ProposalStatus::Submitted, ProposalStatus::OnHold, ProposalStatus::Rejected => 3,
            // The project's own status says how far an approved proposal got:
            // a draft waits on the owner's acknowledgement, an approved
            // project on its report template, and confirming that template
            // activates it (ConfirmReportTemplate), so an active one is running.
            ProposalStatus::Approved => match ($project->status) {
                ProjectStatus::Draft => 3,
                ProjectStatus::Active => self::runningOrClosing($project),
                default => 4,
            },
            ProposalStatus::Active => self::runningOrClosing($project),
            ProposalStatus::FinalReports => 6,
            ProposalStatus::Completed => 7,
        };
    }

    /**
     * The projects standing on a stage, worked out exactly as the Desk does,
     * so the projects register's stage filter and the Project flow panel
     * always count the same projects.
     *
     * @return list<int>
     */
    public static function projectIdsAt(int $stage): array
    {
        return Project::query()
            ->select(['id', 'status', 'end_date'])
            ->with('proposals:id,status')
            ->get()
            ->filter(fn (Project $project): bool => self::stage($project, self::lead($project->proposals)) === $stage)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * A running project moves to closing once its end date has passed.
     */
    private static function runningOrClosing(Project $project): int
    {
        return $project->end_date !== null && $project->end_date->lt(CarbonImmutable::today()) ? 6 : 5;
    }

    /**
     * What is late and what falls due soon. Reads the project's status and
     * end_date, its loaded monthlyReports and, when
     * loaded, proposals.donorProposals.transfers.
     *
     * @return array{late: int, soon: int}
     */
    public static function signals(Project $project): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        $lateReports = $project->monthlyReports->filter(fn ($report): bool => $report->status === MonthlyReportStatus::Draft && $report->period->lt($monthStart))->count();
        $waitingReports = $project->monthlyReports->where('status', MonthlyReportStatus::Submitted)->count();
        $lateTransfers = self::transfers($project)->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late')->count();
        $pastEnd = $project->status === ProjectStatus::Active && $project->end_date !== null && $project->end_date->lt($today) ? 1 : 0;

        return ['late' => $lateReports + $lateTransfers + $pastEnd, 'soon' => $waitingReports];
    }

    /**
     * @param  array{late: int, soon: int}  $signals
     */
    public static function health(int $stage, array $signals): string
    {
        return $stage === 7 ? 'good' : ($signals['late'] > 0 ? 'bad' : ($signals['soon'] > 0 ? 'warn' : 'good'));
    }

    /**
     * The project's transfers across its proposals' agreements, when loaded.
     *
     * @return Collection<int, TransfersTracking>
     */
    public static function transfers(Project $project): Collection
    {
        return $project->proposals->flatMap(fn (Proposal $proposal) => $proposal->relationLoaded('donorProposals') ? $proposal->donorProposals->flatMap->transfers : collect())->values();
    }
}
