<?php

declare(strict_types=1);

namespace App\Support\Desk;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A date said relative to today the way the Desk says it: "today",
 * "tomorrow", "yesterday", "in 3 days" or "3 days ago".
 */
final class RelativeDay
{
    public static function for(CarbonInterface $date): string
    {
        $days = (int) CarbonImmutable::today()->diffInDays($date->toImmutable()->startOfDay(), false);

        return match (true) {
            $days === 0 => __('desk.today'),
            $days === 1 => __('desk.tomorrow'),
            $days === -1 => __('desk.yesterday'),
            $days > 1 => trans_choice('desk.in_days', $days, ['count' => $days]),
            default => trans_choice('desk.days_ago', -$days, ['count' => -$days]),
        };
    }
}
