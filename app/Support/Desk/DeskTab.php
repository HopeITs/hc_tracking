<?php

declare(strict_types=1);

namespace App\Support\Desk;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Desk tabs: on My desk, a page opened from a panel, a button, the sidebar or
 * the Ask bar loads in a tab inside the Desk (an iframe, resources/js/
 * desk-tabs.js) instead of replacing it. Such a page renders without the
 * application's own frame (no sidebar, top bar, Ask bar, tour or live
 * notification channel; the Desk around it has them), which also makes it
 * lighter to serve.
 *
 * A request is for a tab when it carries ?_tab=1 (the Desk adds it, and the
 * page keeps it on every in-tab navigation, resources/js/desk-tab-child.js) or
 * when the browser says it is loading a frame (Sec-Fetch-Dest: iframe), which
 * covers a plain link or a form posted inside a tab.
 */
final class DeskTab
{
    public const string Param = '_tab';

    public static function current(?Request $request = null): bool
    {
        $request ??= request();

        return $request->query(self::Param) === '1' || $request->headers->get('Sec-Fetch-Dest') === 'iframe';
    }

    /**
     * The name of the page's route, also during a Livewire update, where
     * the request itself is Livewire's own endpoint: the page it came from
     * is then read from Livewire's original URL. Null when no route matches.
     */
    public static function pageRouteName(): ?string
    {
        if (! Livewire::isLivewireRequest()) {
            return request()->route()?->getName();
        }

        try {
            return Route::getRoutes()->match(Request::create(Livewire::originalUrl()))->getName();
        } catch (HttpException) {
            return null;
        }
    }
}
