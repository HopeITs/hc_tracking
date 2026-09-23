<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScanner;
use Illuminate\Console\Command;

/**
 * Queues every scanner of one group (see config notifications.scanners).
 */
class ScanNotificationAlerts extends Command
{
    protected $signature = 'notifications:scan {group : frequent or daily}';

    protected $description = 'Queue the notification alert scanners of one group';

    public function handle(): int
    {
        $scanners = config('notifications.scanners.'.$this->argument('group'));

        if (! is_array($scanners)) {
            $this->error(__('notifications.console.unknown_group', ['group' => $this->argument('group')]));

            return self::FAILURE;
        }

        foreach ($scanners as $scanner) {
            RunScanner::dispatch($scanner);
        }

        $this->info(__('notifications.console.queued', ['count' => count($scanners)]));

        return self::SUCCESS;
    }
}
