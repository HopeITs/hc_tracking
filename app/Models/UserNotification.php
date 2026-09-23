<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One row of a user's inbox.
 *
 * Extends Laravel's DatabaseNotification so the Notifiable helpers keep
 * working, and adds the columns the inbox groups, filters and pins on.
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property array<string, mixed> $data
 * @property NotificationPriority $priority
 * @property string $module
 * @property NotificationKind $kind
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $actor_id
 * @property int|null $activity_id
 * @property string|null $group_key
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'priority', 'module', 'kind',
    'subject_type', 'subject_id', 'actor_id', 'activity_id', 'group_key', 'snoozed_until', 'read_at',
])]
class UserNotification extends DatabaseNotification
{
    use Prunable;

    /**
     * Mirror the column defaults for rows that have not been round-tripped.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'info',
        'kind' => 'action',
    ];

    /**
     * Limit to one user's inbox.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey());
    }

    /**
     * Hide rows that are still snoozed.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    /**
     * The user whose action this notification is about.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'json:unicode',
            'priority' => NotificationPriority::class,
            'kind' => NotificationKind::class,
            'notifiable_id' => 'integer',
            'subject_id' => 'integer',
            'actor_id' => 'integer',
            'activity_id' => 'integer',
            'snoozed_until' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Read notifications past the retention period (default 61 days). Unread
     * ones are kept however old.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) config('notifications.retention.read_notification_days')));
    }
}
