<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ReportTemplateStatus;
use App\Events\LocationPeriodChanged;
use App\Events\ReportTemplateConfirmed;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportNames;
use App\Support\MonthlyReportPreparers;
use App\Support\Reporting\PlaceBridge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Generates a report template's monthly reports (contracts §5, §13
 * "generate", T17A, Y-03): on ReportTemplateConfirmed one draft per month of
 * the window of the template's proposals (MonthlyReportGenerator::windowOf()),
 * then one grouped `monthly_reports.generated` notification to the
 * template's preparers: the responsibles of its location rows at its place
 * (D-84, MonthlyReportPreparers), named by its proposals and place (Y-03
 * slice 3); on LocationPeriodChanged the new months of every confirmed
 * template of the row (Y-17), without a notification
 * (`project.period_changed` already tells the responsible). Existing reports are never touched or deleted.
 *
 * Queued, retried and idempotent: MonthlyReportGenerator creates only the
 * missing months, so a re-delivered event creates and notifies nothing. The
 * confirmation committed before the event was dispatched, so nothing here
 * can undo it. On a worker a failure is retried with backoff; when the queue
 * runs inline (sync) it is reported and the job failed, never thrown into
 * the request that confirmed the template.
 */
final class GenerateMonthlyReportsForLocation implements ShouldQueue
{
    use InteractsWithQueue;

    public const GeneratedEvent = 'monthly_reports.generated';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function handleReportTemplateConfirmed(ReportTemplateConfirmed $event): void
    {
        $this->guarded(function () use ($event): void {
            $template = ReportTemplate::query()->find($event->templateId);

            if ($template === null || ! $template->isConfirmed()) {
                return;
            }

            $generator = app(MonthlyReportGenerator::class);
            $window = $generator->windowOf($template);

            if ($window === null) {
                return;
            }

            $created = $generator->generate($template, $window[0], $window[1]);

            if ($created !== []) {
                $this->notifyGenerated($template, $created);
            }
        });
    }

    /**
     * The row's confirmed templates (PlaceBridge::templateIdsOfRow()) get
     * the months of their window they do not have yet. The templates and
     * their proposals load in two queries, whatever their number.
     */
    public function handleLocationPeriodChanged(LocationPeriodChanged $event): void
    {
        $this->guarded(function () use ($event): void {
            $templateIds = app(PlaceBridge::class)->templateIdsOfRow($event->row);

            if ($templateIds === []) {
                return;
            }

            $generator = app(MonthlyReportGenerator::class);
            $templates = ReportTemplate::query()
                ->whereKey($templateIds)
                ->where('status', ReportTemplateStatus::Confirmed)
                ->with(['proposals' => fn ($query) => $query->select(MonthlyReportGenerator::WindowColumns)])
                ->orderBy('id')
                ->get();

            foreach ($templates as $template) {
                $window = $generator->windowOf($template);

                if ($window !== null) {
                    $generator->generate($template, $window[0], $window[1]);
                }
            }
        });
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
     * "The monthly reports of X are ready to prepare", once, for the
     * template's responsibles (D-84). Named directly, so no module permission
     * or mute removes them. The subject is the first new month, so the
     * notification opens a report; its words name the proposals and place.
     *
     * @param  list<int>  $createdIds
     */
    private function notifyGenerated(ReportTemplate $template, array $createdIds): void
    {
        $catalog = app(EventCatalog::class)->effective(self::GeneratedEvent);

        if ($catalog === null || ! $catalog['enabled']) {
            return;
        }

        $userIds = array_values(app(EmployeeRecipients::class)->usersFor(app(MonthlyReportPreparers::class)->responsibleEmployeeIds($template)));
        $recipients = app(RecipientFilter::class)->apply($userIds, $catalog['module'], null, $userIds, $userIds);

        if ($recipients === []) {
            return;
        }

        $names = app(MonthlyReportNames::class)->forTemplates([$template])[$template->id];
        $firstReport = MonthlyReport::query()->whereKey($createdIds)->orderBy('period')->first(['id']);

        $payload = new NotificationPayload(
            kind: NotificationKind::Action,
            module: $catalog['module'],
            priority: NotificationPriority::Important,
            key: 'notifications.events.'.self::GeneratedEvent,
            params: ['subject' => implode(' · ', array_filter([$names['proposalsText'], $names['place']], fn (string $part): bool => $part !== ''))],
            subjectType: (new MonthlyReport)->getMorphClass(),
            subjectId: $firstReport?->id,
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
