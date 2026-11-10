<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the records a person works in (a project, a monthly report, a funding
 * agreement) on users.nav_recent, for the sidebar's "Most used" list. Every
 * successful page load of a record counts one use (a Desk tab's load too);
 * never a Livewire update.
 *
 * The list is ranked by use, not by order of opening: how many times a record
 * was opened, weighted by how lately (opened today counts four times, this
 * week twice, this month once, older half), so the records someone keeps
 * going back to stay on top while a one-off look soon gives way.
 */
class RemembersRecentRecord
{
    /** How many records the sidebar shows. */
    public const int Limit = 5;

    /** How many records are tracked, so a record that drops off the list can climb back. */
    public const int Tracked = 30;

    /**
     * The record pages that count, with the route parameter holding the record and its kind.
     *
     * @var array<string, array{parameter: string, kind: string}>
     */
    public const array Pages = [
        'projects.show' => ['parameter' => 'project', 'kind' => 'project'],
        'monthly-reports.show' => ['parameter' => 'monthlyReport', 'kind' => 'monthly_report'],
        'funding-agreements.show' => ['parameter' => 'donorProposal', 'kind' => 'agreement'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $page = self::Pages[$request->route()?->getName() ?? ''] ?? null;
        $user = $request->user();

        if ($page === null || $user === null || ! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return $response;
        }

        $record = $request->route()->parameter($page['parameter']);

        if (! $record instanceof Model || ! method_exists($record, 'activityLabel')) {
            return $response;
        }

        $routeName = (string) $request->route()->getName();
        $key = (string) $record->getRouteKey();
        $now = now()->getTimestamp();
        $stored = self::entries($user->nav_recent);
        $same = fn (array $item): bool => $item['route'] === $routeName && $item['key'] === $key;
        $previous = collect($stored)->first($same);

        $tracked = collect($stored)
            ->reject($same)
            ->push(['route' => $routeName, 'key' => $key, 'label' => $record->activityLabel(), 'kind' => $page['kind'], 'n' => ($previous['n'] ?? 0) + 1, 't' => $now])
            ->sortByDesc(fn (array $item): float => self::score($item, $now))
            ->take(self::Tracked)
            ->values()
            ->all();

        $user->forceFill(['nav_recent' => $tracked])->saveQuietly();

        return $response;
    }

    /**
     * The records to show, most used first.
     *
     * @return list<array{route: string, key: string, label: string, kind: string, n: int, t: int}>
     */
    public static function mostUsed(mixed $stored, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();

        return collect(self::entries($stored))
            ->sortByDesc(fn (array $item): float => self::score($item, $now))
            ->take(self::Limit)
            ->values()
            ->all();
    }

    /**
     * How much a record counts now: its uses, weighted by how lately it was opened.
     *
     * @param  array{n: int, t: int}  $item
     */
    public static function score(array $item, int $now): float
    {
        $days = max(0, $now - $item['t']) / 86400;
        $weight = match (true) {
            $days < 1 => 4.0,
            $days < 7 => 2.0,
            $days < 30 => 1.0,
            default => 0.5,
        };

        // A tiny pull toward the newest, so equal scores keep the latest first.
        return $item['n'] * $weight + $item['t'] / 1e12;
    }

    /**
     * The stored list, well formed. Entries saved before counting began count as one use, opened
     * a minute apart in the order they were kept (newest first).
     *
     * @return list<array{route: string, key: string, label: string, kind: string, n: int, t: int}>
     */
    private static function entries(mixed $stored): array
    {
        $entries = [];
        $fallback = now()->getTimestamp();

        foreach (is_array($stored) ? $stored : [] as $item) {
            if (! is_array($item) || ! is_string($item['route'] ?? null) || ! is_scalar($item['key'] ?? null) || ! is_string($item['label'] ?? null) || ! is_string($item['kind'] ?? null)) {
                continue;
            }

            $entries[] = [
                'route' => $item['route'],
                'key' => (string) $item['key'],
                'label' => $item['label'],
                'kind' => $item['kind'],
                'n' => is_int($item['n'] ?? null) ? max(1, $item['n']) : 1,
                't' => is_int($item['t'] ?? null) ? $item['t'] : $fallback,
            ];
            $fallback -= 60;
        }

        return $entries;
    }
}
