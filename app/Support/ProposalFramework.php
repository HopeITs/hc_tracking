<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProposalStatus;
use App\Models\Proposal;

/**
 * The rules a proposal's own logical framework shares with every writer
 * (contracts §11.2): which programs it spans, the program its roots take,
 * and when its structure locks.
 */
final class ProposalFramework
{
    /**
     * The distinct programs of the proposal's linked projects, ascending.
     *
     * @return list<int>
     */
    public static function programIds(Proposal $proposal): array
    {
        return array_values($proposal->projects()
            ->whereNotNull('projects.program_id')
            ->distinct()
            ->orderBy('projects.program_id')
            ->pluck('projects.program_id')
            ->map(fn (int|string $programId): int => (int) $programId)
            ->all());
    }

    /**
     * The program a proposal root takes: the one program all its projects
     * share, else null ("Multi-program", D-24).
     *
     * @param  list<int>|null  $programIds  pass programIds() when already read
     */
    public static function sharedProgramId(Proposal $proposal, ?array $programIds = null): ?int
    {
        $programIds ??= self::programIds($proposal);

        return count($programIds) === 1 ? $programIds[0] : null;
    }

    /**
     * Whether the framework's structure is locked: the proposal's stored
     * status has reached `submitted` on the main line (§1 order). On hold and
     * rejected take the status they left from; the deprecated final reports
     * counts as past submission.
     */
    public static function isStructureLocked(Proposal $proposal): bool
    {
        $status = $proposal->status;

        foreach ([ProposalStatus::Rejected, ProposalStatus::OnHold] as $branch) {
            if ($status === $branch) {
                $status = $proposal->statusBefore($branch) ?? ProposalStatus::ConceptNote;
            }
        }

        if ($status === ProposalStatus::FinalReports) {
            return true;
        }

        $order = ProposalStatus::lifecycle();
        $position = array_search($status, $order, true);

        return $position !== false && $position >= array_search(ProposalStatus::Submitted, $order, true);
    }
}
