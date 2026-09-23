<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Notifications\Inbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Mark as read" from a desktop popup or toast: reads one of the signed-in
 * user's own notifications without leaving the page they are on.
 */
class MarkNotificationReadController extends Controller
{
    public function __invoke(Request $request, string $notification, Inbox $inbox): Response
    {
        $row = UserNotification::query()->forUser($request->user())->findOrFail($notification);

        $inbox->markRead($request->user(), $row->id);

        return response()->noContent();
    }
}
