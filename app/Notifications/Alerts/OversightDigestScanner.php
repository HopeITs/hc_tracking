<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\EventImportance;
use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Notifications\RecipientResolver;
use App\Notifications\Recipients\OversightRecipients;
use Carbon\CarbonImmutable;

/**
 * The daily oversight summary (contracts.md §15.1): `digest` catalog events
 * reach MEAL and General Management only here. Each working morning, every
 * oversight user gets one in-app summary of the movements since the previous
 * working day, counted per event. Oversight is non-direct: the module's view
 * permission and mutes apply, and nobody hears about their own movements.
 * Counts only, never amounts.
 */
final class OversightDigestScanner implements Scanner
{
    public function __construct(
        private WorkingCalendar $calendar,
        private EventCatalog $catalog,
        private OversightRecipients $oversight,
        private RecipientResolver $resolver,
        private RecipientFilter $filter,
        private AlertDispatcher $dispatcher,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $digestKeys = $this->digestKeysByActivity();

        if ($digestKeys === []) {
            return 0;
        }

        $since = $this->calendar->lastWorkingDayOnOrBefore($today->subDay());
        $counts = $this->countsByUserAndModule($digestKeys, $since, $today);
        $sent = 0;

        foreach ($this->visibleCounts($counts) as $userId => $eventCounts) {
            arsort($eventCounts);

            $payload = new NotificationPayload(
                kind: NotificationKind::Digest,
                module: 'system',
                priority: NotificationPriority::Info,
                key: 'notifications.digests.oversight',
                params: [
                    'count' => (string) array_sum($eventCounts),
                    'date' => ['date' => $since->toDateString()],
                    'counts' => ['event_counts' => $eventCounts],
                ],
                subjectType: 'user',
                subjectId: $userId,
                link: ['route' => 'dashboard'],
            );

            $sent += $this->dispatcher->send('oversight_digest', $today->toDateString(), $payload, [$userId], [], [$userId]);
        }

        return $sent;
    }

    /**
     * Activity event => catalog key, for every enabled key whose effective
     * importance is `digest` (admin overrides included).
     *
     * @return array<string, string>
     */
    private function digestKeysByActivity(): array
    {
        $keys = [];

        foreach (EventCatalog::keys() as $key) {
            $event = $this->catalog->effective($key);

            if ($event !== null && $event['enabled'] && $event['importance'] === EventImportance::Digest && $event['activity'] !== null) {
                $keys[$event['activity']] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $digestKeys
     * @return array<string, array<int, array<string, int>>> module => user id => catalog key => count
     */
    private function countsByUserAndModule(array $digestKeys, CarbonImmutable $since, CarbonImmutable $until): array
    {
        $recipients = [];
        $counts = [];

        $activities = Activity::query()
            ->whereIn('event', array_keys($digestKeys))
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->get(['id', 'actor_id', 'subject_type', 'subject_id', 'module', 'event', 'properties']);

        foreach ($activities as $activity) {
            $key = $digestKeys[$activity->event];
            $projectId = $this->resolver->projectIdOf($activity);
            $recipients[$key.'|'.$projectId] ??= $this->oversight->userIdsFor($key, $projectId);

            foreach ($recipients[$key.'|'.$projectId] as $userId) {
                if ($userId === $activity->actor_id) {
                    continue;
                }

                $counts[$activity->module][$userId][$key] = ($counts[$activity->module][$userId][$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Keep each module's counts only for users who may view it and did not
     * mute it, then merge per user.
     *
     * @param  array<string, array<int, array<string, int>>>  $counts
     * @return array<int, array<string, int>> user id => catalog key => count
     */
    private function visibleCounts(array $counts): array
    {
        $merged = [];

        foreach ($counts as $module => $byUser) {
            foreach ($this->filter->apply(array_keys($byUser), $module, null) as $userId) {
                foreach ($byUser[$userId] as $key => $count) {
                    $merged[$userId][$key] = ($merged[$userId][$key] ?? 0) + $count;
                }
            }
        }

        ksort($merged);

        return $merged;
    }
}
