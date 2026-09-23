<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;

/**
 * One grouped query per user, cached, invalidated whenever that user's inbox
 * changes (arrival, read, unread, snooze, wake).
 */
final class UnreadCounts
{
    /**
     * @return array{total: int, critical: int, modules: array<string, int>}
     */
    public function for(User $user): array
    {
        return Cache::remember($this->key($user->id), now()->addMinutes(10), function () use ($user): array {
            $rows = UserNotification::query()
                ->forUser($user)
                ->whereNull('read_at')
                ->visible()
                ->toBase()
                ->selectRaw('module, priority, count(*) as aggregate')
                ->groupBy('module', 'priority')
                ->get();

            $counts = ['total' => 0, 'critical' => 0, 'modules' => []];

            foreach ($rows as $row) {
                $count = (int) $row->aggregate;
                $counts['total'] += $count;
                $counts['modules'][$row->module] = ($counts['modules'][$row->module] ?? 0) + $count;

                if ($row->priority === NotificationPriority::Critical->value) {
                    $counts['critical'] += $count;
                }
            }

            ksort($counts['modules']);

            return $counts;
        });
    }

    public function forget(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "notifications.unread.{$userId}";
    }
}
