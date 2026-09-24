<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScanner;
use App\Notifications\Alerts\DailyBriefScanner;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WeeklyDigestScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every minute; queues the daily brief and the weekly digest once their
 * admin-configured time has passed. The scanners themselves decide working
 * days and the ledger stops duplicates.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:digests';

    protected $description = 'Queue the daily brief and weekly digest when their time has come';

    public function handle(NotificationSettings $settings): int
    {
        $now = CarbonImmutable::now();
        $clock = $now->format('H:i');

        if ($clock >= (string) $settings->get('daily_brief_time') && Cache::add('notifications.digests.daily.'.$now->toDateString(), true, $now->endOfDay())) {
            RunScanner::dispatch(DailyBriefScanner::class);
        }

        if ($clock >= (string) $settings->get('weekly_digest_time') && Cache::add('notifications.digests.weekly.'.$now->toDateString(), true, $now->endOfDay())) {
            RunScanner::dispatch(WeeklyDigestScanner::class);
        }

        return self::SUCCESS;
    }
}
