<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One of a user's sessions was signed out (sign-out, "sign out other
 * devices", HR deactivation or password change). Broadcast on their private
 * channel as ".session.ended" with no payload: every open tab of that user
 * drops its socket and reconnects, and a tab whose session is gone is refused
 * by /broadcasting/auth and stays disconnected, so a signed-out device stops
 * receiving live notifications.
 *
 * Sent immediately: it is raised inside the sign-out request, and a queued
 * copy would leave the old tabs streaming until a worker picked it up.
 */
final class SessionEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'session.ended';
    }

    /**
     * @return array<string, never>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
