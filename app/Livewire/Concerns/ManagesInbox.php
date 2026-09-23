<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\UserNotification;
use App\Notifications\Inbox;
use App\Notifications\QuickActions;
use Flux\Flux;

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

    /**
     * Run a one-click action offered inside a notification (see QuickActions).
     */
    public function runQuickAction(string $id, string $type): void
    {
        $user = auth()->user();
        $notification = UserNotification::query()->forUser($user)->find($id);

        if ($notification === null) {
            return;
        }

        $result = app(QuickActions::class)->run($user, $notification, $type);

        if ($type !== 'alert.snooze') {
            app(Inbox::class)->markRead($user, $id);
        }

        $this->dispatch('notification-received');

        if ($result['redirect'] !== null) {
            $this->redirect($result['redirect'], navigate: true);

            return;
        }

        Flux::toast(variant: $result['ok'] ? 'success' : 'warning', text: __($result['message']));
    }
}
