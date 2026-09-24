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
 *
 * Reading and snoozing also announce an `inbox-undoable` browser event with
 * the ids they actually changed, so the inbox page can offer an undo.
 */
trait ManagesInbox
{
    public function markRead(string $id): void
    {
        $this->markIdsRead([$id]);
    }

    public function markUnread(string $id): void
    {
        app(Inbox::class)->markUnread(auth()->user(), $id);
        $this->dispatch('notification-received');
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function markIdsRead(array $ids): void
    {
        $user = auth()->user();
        $inbox = app(Inbox::class);
        $changed = $inbox->unreadAmong($user, array_values(array_filter($ids, 'is_string')));

        $inbox->markManyRead($user, $changed);
        $this->dispatch('notification-received');
        $this->announceUndo('read', $changed);
    }

    public function markAllRead(): void
    {
        $user = auth()->user();
        $inbox = app(Inbox::class);
        $changed = $inbox->visibleUnreadIds($user);

        $inbox->markManyRead($user, $changed);
        $this->dispatch('notification-received');
        $this->announceUndo('read', $changed);
    }

    public function snooze(string $id, string $preset): void
    {
        $this->snoozeIds([$id], $preset);
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function snoozeIds(array $ids, string $preset): void
    {
        if (! in_array($preset, Inbox::SnoozePresets, true)) {
            return;
        }

        $ids = array_values(array_filter($ids, 'is_string'));

        if ($ids === []) {
            return;
        }

        app(Inbox::class)->snoozeMany(auth()->user(), $ids, $preset);
        $this->dispatch('notification-received');
        $this->announceUndo('snooze', $ids);
    }

    public function unsnooze(string $id): void
    {
        app(Inbox::class)->unsnooze(auth()->user(), $id);
        $this->dispatch('notification-received');
    }

    /**
     * Reverse the last read or snooze. Scoped like every other action, so
     * ids from the browser can only ever touch my own rows.
     *
     * @param  array<int, mixed>  $ids
     */
    public function undoInbox(string $action, array $ids): void
    {
        $user = auth()->user();
        $ids = array_values(array_filter($ids, 'is_string'));

        match ($action) {
            'read' => app(Inbox::class)->markManyUnread($user, $ids),
            'snooze' => app(Inbox::class)->unsnoozeMany($user, $ids),
            default => null,
        };

        $this->dispatch('notification-received');
    }

    /**
     * Run a one-click action offered inside a notification (see QuickActions).
     * The notification is read only when the action actually went through;
     * a refused or outdated action leaves it for the person to open.
     */
    public function runQuickAction(string $id, string $type): void
    {
        $user = auth()->user();
        $notification = UserNotification::query()->forUser($user)->find($id);

        if ($notification === null) {
            return;
        }

        $result = app(QuickActions::class)->run($user, $notification, $type);

        if ($result['ok'] && $type !== 'alert.snooze') {
            app(Inbox::class)->markRead($user, $id);
        }

        $this->dispatch('notification-received');

        if ($result['redirect'] !== null) {
            $this->redirect($result['redirect'], navigate: true);

            return;
        }

        Flux::toast(variant: $result['ok'] ? 'success' : 'warning', text: __($result['message']));
    }

    /**
     * @param  list<string>  $ids
     */
    private function announceUndo(string $action, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->dispatch(
            'inbox-undoable',
            action: $action,
            ids: $ids,
            message: trans_choice("notifications.undo.{$action}", count($ids), ['count' => count($ids)]),
        );
    }
}
