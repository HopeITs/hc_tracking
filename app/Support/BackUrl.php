<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Where a detail page's Back control should lead: the page the visitor came
 * from when it is one of ours, otherwise the record's own register.
 */
final class BackUrl
{
    /**
     * The HTTP referer when it points inside this app at a different page
     * than the current one, else the given fallback.
     */
    public static function for(string $fallback): string
    {
        $request = request();
        $referer = trim((string) $request->headers->get('referer', ''));

        if ($referer === '' || ! self::isInApp($referer, $request) || self::isCurrentPage($referer)) {
            return $fallback;
        }

        return $referer;
    }

    private static function isInApp(string $url, Request $request): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === strtolower($request->getHost()) || ($appHost !== '' && $host === $appHost);
    }

    /**
     * Same host and path as the page being rendered. During a Livewire round
     * trip the referer is the page itself, so the Livewire original URL is
     * what counts as "current", and query strings are ignored on both sides.
     */
    private static function isCurrentPage(string $url): bool
    {
        $current = Livewire::isLivewireRequest() ? Livewire::originalUrl() : url()->current();

        return self::hostAndPath($url) === self::hostAndPath($current);
    }

    private static function hostAndPath(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $host.'/'.ltrim($path, '/');
    }
}
