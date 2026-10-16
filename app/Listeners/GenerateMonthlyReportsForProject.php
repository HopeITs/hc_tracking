<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Events\ProjectActivated;
use App\Events\ProjectPeriodChanged;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Support\MonthlyReportGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Generates a project's monthly reports (contracts §5, §13 "generate", T17A):
 * on ProjectActivated one draft per month of the period, then one grouped
 * `monthly_reports.generated` notification to the project owner; on
 * ProjectPeriodChanged the new months only, without a notification
 * (`project.period_changed` already tells the owner). Existing reports are
 * never touched or deleted.
 *
 * Queued, retried and idempotent: MonthlyReportGenerator creates only the
 * missing months, so a re-delivered event creates and notifies nothing. The
 * activation committed before the event was dispatched, so nothing here can
 * undo it. On a worker a failure is retried with backoff; when the queue
 * runs inline (sync) it is reported and the job failed, never thrown into
 * the request that activated the project.
 */
final class GenerateMonthlyReportsForProject implements ShouldQueue
{
    use InteractsWithQueue;

    public const GeneratedEvent = 'monthly_reports.generated';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function handleProjectActivated(ProjectActivated $event): void
    {
        $this->guarded(function () use ($event): void {
            $project = $this->activeProject($event->projectId);

            if ($project === null) {
                return;
            }

            $created = app(MonthlyReportGenerator::class)->generate($project, $event->startDate, $event->endDate);

            if ($created !== []) {
                $this->notifyGenerated($project);
            }
        });
    }

    public function handleProjectPeriodChanged(ProjectPeriodChanged $event): void
    {
        $this->guarded(function () use ($event): void {
            $project = $this->activeProject($event->projectId);

            if ($project !== null) {
                app(MonthlyReportGenerator::class)->generate($project, $event->newStart, $event->newEnd);
            }
        });
    }

    /**
     * The project reloaded, or null when it is gone or no longer active.
     */
    private function activeProject(int $projectId): ?Project
    {
        $project = Project::query()->find($projectId);

        return $project?->status === ProjectStatus::Active ? $project : null;
    }

    /**
     * Run the work; inline, a failure is reported instead of thrown.
     */
    private function guarded(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            if (! $this->runsInline()) {
                throw $exception;
            }

            report($exception);
            $this->fail($exception);
        }
    }

    /**
     * "The monthly reports of X are ready to prepare", once, for the project
     * owner. Named directly, so no module permission or mute removes them.
     */
    private function notifyGenerated(Project $project): void
    {
        $catalog = app(EventCatalog::class)->effective(self::GeneratedEvent);

        if ($catalog === null || ! $catalog['enabled']) {
            return;
        }

        $userIds = array_values(app(EmployeeRecipients::class)->usersFor(array_filter([$project->user_id])));
        $recipients = app(RecipientFilter::class)->apply($userIds, $catalog['module'], null, $userIds, $userIds);

        if ($recipients === []) {
            return;
        }

        $payload = new NotificationPayload(
            kind: NotificationKind::Action,
            module: $catalog['module'],
            priority: NotificationPriority::Important,
            key: 'notifications.events.'.self::GeneratedEvent,
            params: ['subject' => $project->name],
            subjectType: $project->getMorphClass(),
            subjectId: $project->id,
        );

        foreach (User::query()->whereIn('id', $recipients)->get() as $user) {
            rescue(fn () => $user->notify(new AppNotification($payload)));
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
