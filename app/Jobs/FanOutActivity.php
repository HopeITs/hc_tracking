<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\ActivityParameters;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\NotificationPayload;
use App\Notifications\QuickActions;
use App\Notifications\RecipientResolver;
use App\Notifications\ResolvedRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns one activity into notifications. Idempotent: a recipient whose inbox
 * already holds this activity (directly or merged) is skipped, so a retry
 * never doubles anything.
 */
final class FanOutActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $activityId) {}

    public function handle(RecipientResolver $resolver, ActivityParameters $parameters, EmployeeRecipients $employees, QuickActions $quickActions): void
    {
        $activity = Activity::query()->with('actor:id,name')->find($this->activityId);

        if ($activity === null || ! $activity->shouldNotify()) {
            return;
        }

        $recipients = $resolver->resolve($activity);

        if ($recipients->priorities !== []) {
            $this->notifyRecipients($activity, $recipients, $parameters->for($activity), $quickActions);
        }

        $this->warnActorAboutUnnotified($activity, $recipients, $employees);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function notifyRecipients(Activity $activity, ResolvedRecipients $recipients, array $params, QuickActions $quickActions): void
    {
        User::query()->whereIn('id', array_keys($recipients->priorities))->get()->each(
            function (User $user) use ($activity, $recipients, $params, $quickActions): void {
                if ($this->alreadyDelivered($user, $activity)) {
                    return;
                }

                $user->notify(new AppNotification(NotificationPayload::fromActivity(
                    $activity,
                    $recipients->priorities[$user->id],
                    $params,
                    in_array($user->id, $recipients->assignedUserIds, true),
                    $activity->event === $activity->subject_type.'.deleted' ? [] : $quickActions->forSubject($activity->subject_type, $activity->subject_id, $user),
                )));
            },
        );
    }

    /**
     * Assigned people without a login account are skipped; the actor is told
     * who, so they can pass the news on themselves.
     */
    private function warnActorAboutUnnotified(Activity $activity, ResolvedRecipients $recipients, EmployeeRecipients $employees): void
    {
        if ($recipients->unnotifiedEmployeeIds === [] || $activity->actor_id === null) {
            return;
        }

        $actor = User::query()->find($activity->actor_id);

        if ($actor === null || $this->alreadyDelivered($actor, $activity)) {
            return;
        }

        $actor->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action,
            module: $activity->module,
            priority: NotificationPriority::Important,
            key: 'notifications.warnings.not_notified',
            params: [
                'subject' => $activity->label(),
                'names' => ['localized_list' => array_values($employees->names($recipients->unnotifiedEmployeeIds))],
            ],
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id,
            actorId: $activity->actor_id,
            activityId: $activity->id,
        )));
    }

    private function alreadyDelivered(User $user, Activity $activity): bool
    {
        return UserNotification::query()
            ->forUser($user)
            ->whereJsonContains('data->activity_ids', $activity->id)
            ->exists();
    }
}
