<?php

declare(strict_types=1);

namespace App\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Writes a refused request (a 403) to the activity log, so repeated probing
 * of pages or actions someone may not use shows up in the audit trail.
 *
 * Only signed-in users are recorded (a guest is sent to the login page, not
 * refused). The same person refused the same action is recorded at most once
 * a minute, so a retrying page cannot flood the log. Nobody is notified.
 */
final class DeniedAccessRecorder
{
    /**
     * The longest action description stored.
     */
    private const MaxActionLength = 191;

    public function record(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $action = $this->describe($request);

        if (! Cache::add('security.access-denied:'.$user->id.':'.sha1($action), true, now()->addMinute())) {
            return;
        }

        $user->recordAccessDenied(['action' => $action, 'ip' => $request->ip()]);
    }

    /**
     * What was refused: the Livewire component and method for an update
     * request, otherwise the HTTP method and path (never the query string,
     * which can carry search terms).
     */
    public function describe(Request $request): string
    {
        $actions = [];

        foreach ((array) $request->input('components', []) as $component) {
            if (! is_array($component)) {
                continue;
            }

            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = is_array($snapshot) ? (string) ($snapshot['memo']['name'] ?? 'component') : 'component';
            $calls = array_filter((array) ($component['calls'] ?? []), 'is_array');

            if ($calls === []) {
                $actions[] = $name;

                continue;
            }

            foreach ($calls as $call) {
                $actions[] = $name.'::'.(string) ($call['method'] ?? '?');
            }
        }

        $description = $actions === []
            ? $request->method().' /'.ltrim($request->path(), '/')
            : implode(', ', array_unique($actions));

        return mb_substr($description, 0, self::MaxActionLength);
    }
}
