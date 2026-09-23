<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use Illuminate\Support\Facades\Lang;

/**
 * Everything one notification needs, independent of who receives it.
 *
 * `key` + `params` are rendered in the reader's language later; params are
 * plain strings or typed arrays understood by NotificationPresenter.
 */
final readonly class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array{route?: string, parameters?: array<string, mixed>}  $link  used when there is no subject page
     * @param  list<array<string, mixed>>  $actions  quick actions
     */
    public function __construct(
        public NotificationKind $kind,
        public string $module,
        public NotificationPriority $priority,
        public string $key,
        public array $params,
        public ?string $subjectType,
        public ?int $subjectId,
        public ?int $actorId = null,
        public ?int $activityId = null,
        public ?string $groupKey = null,
        public array $link = [],
        public array $actions = [],
    ) {}

    /**
     * The payload for one recipient of an action activity. The person being
     * assigned gets the "…you…" wording when the event has one.
     *
     * @param  array<string, mixed>  $params
     * @param  list<array<string, mixed>>  $actions
     */
    public static function fromActivity(Activity $activity, NotificationPriority $priority, array $params, bool $assignedToRecipient = false, array $actions = []): self
    {
        $key = 'notifications.events.'.$activity->event;

        if ($assignedToRecipient && Lang::has($key.'_you')) {
            $key .= '_you';
        }

        return new self(
            kind: NotificationKind::Action,
            module: $activity->module,
            priority: $priority,
            key: $key,
            params: $params,
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id,
            actorId: $activity->actor_id,
            activityId: $activity->id,
            groupKey: ($activity->actor_id ?? 'system').':'.$activity->subject_type.':'.$activity->subject_id,
            actions: $actions,
        );
    }
}
