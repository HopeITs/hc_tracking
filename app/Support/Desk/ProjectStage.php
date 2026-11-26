<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalLocation;
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

    public static function stage(ProposalLocation $row, ?Proposal $lead): int
    {
        if (in_array($row->status, [ProjectStatus::Closed, ProjectStatus::Archived], true)) {
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
            ProposalStatus::Approved => match ($row->status) {
                ProjectStatus::Draft => 3,
                ProjectStatus::Active => self::runningOrClosing($row),
                default => 4,
            },
            ProposalStatus::Active => self::runningOrClosing($row),
            ProposalStatus::FinalReports => 6,
            ProposalStatus::Completed => 7,
        };
    }

    /**
     * The location rows standing on a stage, worked out exactly as the Desk
     * does, so the projects register's stage filter and the Project flow panel
     * always count the same rows (Y-16, D-93).
     *
     * @return list<int>
     */
    public static function locationIdsAt(int $stage): array
    {
        return ProposalLocation::query()
            ->select(['id', 'status', 'end_date', 'proposal_id'])
            ->with('proposal:id,status')
            ->get()
            ->filter(fn (ProposalLocation $row): bool => self::stage($row, self::leadOf($row)) === $stage)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The proposal that leads a row: its own, since a row is of exactly one
     * (D-93). The collection form stays for the Desk's project panels until
     * they move.
     */
    public static function leadOf(ProposalLocation $row): ?Proposal
    {
        return $row->proposal;
    }

    /**
     * A running project moves to closing once its end date has passed.
     */
    private static function runningOrClosing(ProposalLocation $row): int
    {
        return $row->end_date !== null && $row->end_date->lt(CarbonImmutable::today()) ? 6 : 5;
    }

    /**
     * What is late and what falls due soon. Reads the row's status and
     * end_date, the monthly reports at its place (ProposalLocation::
     * monthlyReports(), filled in one read for a list) and, when loaded, its
     * proposal's donorProposals.transfers.
     *
     * @return array{late: int, soon: int}
     */
    public static function signals(ProposalLocation $row): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $reports = $row->monthlyReports();

        $lateReports = $reports->filter(fn ($report): bool => $report->status === MonthlyReportStatus::Draft && $report->period->lt($monthStart))->count();
        $waitingReports = $reports->where('status', MonthlyReportStatus::Submitted)->count();
        $lateTransfers = self::transfers($row)->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late')->count();
        $pastEnd = $row->status === ProjectStatus::Active && $row->end_date !== null && $row->end_date->lt($today) ? 1 : 0;

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
    public static function transfers(ProposalLocation $row): Collection
    {
        $proposal = $row->proposal;

        return $proposal !== null && $proposal->relationLoaded('donorProposals')
            ? $proposal->donorProposals->flatMap->transfers->values()
            : collect();
    }
}
