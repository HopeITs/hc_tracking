<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\Inbox;
use Illuminate\Console\Command;

/**
 * Returns notifications whose snooze has ended to their owners' inboxes.
 */
class WakeSnoozedNotifications extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:wake-snoozed';

    /**
     * @var string
     */
    protected $description = 'Return snoozed notifications whose snooze has ended';

    public function handle(Inbox $inbox): int
    {
        $this->info(__('notifications.console.woken', ['count' => $inbox->wakeDue()]));

        return self::SUCCESS;
    }
}
