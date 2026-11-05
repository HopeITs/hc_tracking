<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Proposals\CreateStageTasks;
use App\Events\ProposalStatusChanged;
use App\Models\Proposal;
use App\Models\ProposalStageTaskTemplate;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * When a proposal enters a status, creates the department work that stage's
 * templates ask for (D-63, contracts.md §5, §6).
 *
 * Queued and idempotent, like PrefillReportTemplates: the status committed
 * before the event was dispatched, so nothing here can undo it. On a worker
 * a failure is retried with backoff; when the queue runs inline it is
 * reported and the job failed, never thrown into the mover's request.
 *
 * The job runs as the person who moved the proposal. The event carries them,
 * and the final-report path authorises and stamps `created_by` from the
 * current user, so without this the only alternatives would be to drop that
 * authorisation or to invent an actor. The user is restored afterwards.
 */
final class CreateStageTasksOnStatusChanged implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Only a status that has templates is worth a job.
     */
    public function shouldQueue(ProposalStatusChanged $event): bool
    {
        return ProposalStageTaskTemplate::query()->forStatus($event->to)->exists();
    }

    public function handle(ProposalStatusChanged $event): void
    {
        try {
            $this->create($event);
        } catch (Throwable $exception) {
            if (! $this->runsInline()) {
                throw $exception;
            }

            report($exception);
            $this->fail($exception);
        }
    }

    /**
     * Reload the proposal and the actor, then run the writer as them.
     */
    private function create(ProposalStatusChanged $event): void
    {
        $proposal = Proposal::query()->find($event->proposalId);

        if ($proposal === null) {
            return;
        }

        $actor = $event->actorId === null ? null : User::query()->find($event->actorId);
        $previous = Auth::user();

        if ($actor !== null) {
            Auth::setUser($actor);
        }

        try {
            app(CreateStageTasks::class)->handle($proposal, $event->to);
        } finally {
            $previous === null ? Auth::forgetUser() : Auth::setUser($previous);
        }
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
