<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Notifications\Inbox;
use App\Notifications\SubjectLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opening a notification reads it, then leads to its record — or back to the
 * inbox with a note when that record was deleted.
 */
class OpenNotificationController extends Controller
{
    public function __invoke(Request $request, string $notification, Inbox $inbox, SubjectLinks $links): RedirectResponse
    {
        $row = UserNotification::query()->forUser($request->user())->findOrFail($notification);

        $inbox->markRead($request->user(), $row->id);

        $url = $links->urlFor($row);

        return $url === null
            ? redirect()->route('notifications.index')->with('notification-missing', true)
            : redirect($url);
    }
}
