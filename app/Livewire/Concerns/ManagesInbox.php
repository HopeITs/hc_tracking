<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Notifications\Inbox;

/**
 * Inbox actions shared by the bell and the /notifications page. Inbox scopes
 * every action to the signed-in user; each action tells every inbox view on
 * the page to refresh.
 */
trait ManagesInbox
{
    public function markRead(string $id): void
    {
        app(Inbox::class)->markRead(auth()->user(), $id);
        $this->dispatch('notification-received');
    }

    public function markUnread(string $id): void
    {
        app(Inbox::class)->markUnread(auth()->user(), $id);
        $this->dispatch('notification-received');
    }

    public function markAllRead(): void
    {
        app(Inbox::class)->markAllRead(auth()->user());
        $this->dispatch('notification-received');
    }

    public function snooze(string $id, string $preset): void
    {
        if (! in_array($preset, Inbox::SnoozePresets, true)) {
            return;
        }

        app(Inbox::class)->snooze(auth()->user(), $id, $preset);
        $this->dispatch('notification-received');
    }
}
