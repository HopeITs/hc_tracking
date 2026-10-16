<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\ProposalPlan\RebaseProposalPlan;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * On donor approval, moves the proposal's plan to the grant period
 * (contracts.md §5, §12.2) through RebaseProposalPlan, with the approval's
 * status note. Listens to ProposalStatusChanged only, never ProjectActivated.
 *
 * Queued, retried and idempotent: the action's ledger shifts the plan once
 * per approval note however often this runs. The approval committed before
 * the event was dispatched, so nothing here can undo it. On a worker a
 * failure is retried with backoff; when the queue runs inline (sync) it is
 * reported and the job failed, never thrown into the approver's request.
 */
final class RebaseProposalPlanOnApproval implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Only an approval is worth a job.
     */
    public function shouldQueue(ProposalStatusChanged $event): bool
    {
        return $event->to === ProposalStatus::Approved;
    }

    public function handle(ProposalStatusChanged $event): void
    {
        if ($event->to !== ProposalStatus::Approved) {
            return;
        }

        try {
            $this->rebase($event->proposalId);
        } catch (Throwable $exception) {
            if (! $this->runsInline()) {
                throw $exception;
            }

            report($exception);
            $this->fail($exception);
        }
    }

    /**
     * Reload the proposal and its latest approval note, then rebase. The
     * action is resolved here, inside the guarded block, so even a failure to
     * build it stays out of the approver's request.
     */
    private function rebase(int $proposalId): void
    {
        $proposal = Proposal::query()->find($proposalId);

        if ($proposal === null) {
            return;
        }

        $approvalNote = ProposalStatusNote::query()
            ->where('proposal_id', $proposal->id)
            ->where('status', ProposalStatus::Approved)
            ->orderByDesc('id')
            ->first();

        if ($approvalNote === null) {
            return;
        }

        app(RebaseProposalPlan::class)->handle($proposal, $approvalNote);
    }

    /**
     * Whether this runs inside the request that raised the event: the sync
     * queue, or a direct call.
     */
    private function runsInline(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob;
    }
}
