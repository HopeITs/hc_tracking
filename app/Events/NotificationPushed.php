<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification arrived (or woke from a snooze) for one user. Broadcast on
 * their private channel as ".notification.pushed"; the payload is already
 * rendered in their language.
 */
final class NotificationPushed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, string>  $payload
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
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
