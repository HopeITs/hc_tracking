<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification arrived (or woke from a snooze) for one user. Broadcast on
 * their private channel as ".notification.pushed"; the payload is already
 * rendered in their language.
 *
 * Sent immediately, not queued: it is only ever raised from work that is
 * already queued (fan-out, scanners, the snooze wake-up), and a second queue
 * hop only delayed the toast. NotificationBroadcaster swallows a failure.
 */
final class NotificationPushed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, string|null>  $payload
     */
    public function __construct(public int $userId, public array $payload) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.pushed';
    }

    /**
     * @return array<string, string|null>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
