<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Desk\DeskTab;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A redirect answered to a page inside a Desk tab keeps the tab marker
 * (?_tab=1) on its target. Without it, a redirect followed by Livewire's
 * navigate fetch (for example a proposal link redirected to its project
 * Story, RedirectProposalToStory) drew the whole application, sidebar and
 * top bar included, inside the tab: a screen inside a screen (user report
 * 2026-11-12). Only same-site targets are touched.
 */
class KeepDeskTabOnRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse || ! DeskTab::current($request)) {
            return $response;
        }

        $target = $response->getTargetUrl();
        $parts = parse_url($target);
        $host = $parts['host'] ?? null;

        if ($host !== null && $host !== $request->getHost()) {
            return $response;
        }

        parse_str($parts['query'] ?? '', $query);

        if (($query[DeskTab::Param] ?? null) === '1') {
            return $response;
        }

        $query[DeskTab::Param] = '1';
        $base = strtok($target, '?#') ?: $target;
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        $response->setTargetUrl($base.'?'.http_build_query($query).$fragment);

        return $response;
    }
}
