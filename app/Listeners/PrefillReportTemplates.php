<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * On donor approval, pre-fills the monthly report template of every project
 * the proposal funds as `pending` (contracts.md §5, §12.4, T33) through
 * ReportTemplatePrefiller, which also tells MEAL (`report_template.pending`).
 * An active project receiving a new proposal gets pending lines only (D-41).
 *
 * Queued, retried and idempotent: one template per project and one line per
 * target, however often this runs. The approval committed before the event
 * was dispatched, so nothing here can undo it. On a worker a failure is
 * retried with backoff; when the queue runs inline (sync) it is reported and
 * the job failed, never thrown into the approver's request.
 */
final class PrefillReportTemplates implements ShouldQueue
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
            $this->prefill($event->proposalId);
        } catch (Throwable $exception) {
            if (! $this->runsInline()) {
                throw $exception;
            }

            report($exception);
            $this->fail($exception);
        }
    }

    /**
     * Reload the proposal, then pre-fill. The pre-filler is resolved here,
     * inside the guarded block, so even a failure to build it stays out of
     * the approver's request.
     */
    private function prefill(int $proposalId): void
    {
        $proposal = Proposal::query()->find($proposalId);

        if ($proposal === null) {
            return;
        }

        app(ReportTemplatePrefiller::class)->prefillForProposal($proposal);
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
