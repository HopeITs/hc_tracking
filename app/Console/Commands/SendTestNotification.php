<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\HrUser;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Illuminate\Console\Command;

/**
 * Sends one notification to a user, to try out toasts and desktop popups.
 * The delay leaves time to switch away from the tab: popups only appear
 * while it is hidden.
 */
class SendTestNotification extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:test
        {email : The user to notify}
        {--priority=important : info, important or critical}
        {--delay=5 : Seconds to wait before sending}';

    /**
     * @var string
     */
    protected $description = 'Send a test notification to a user (to preview toasts and desktop popups)';

    public function handle(): int
    {
        $hrUserId = HrUser::query()->where('email', $this->argument('email'))->value('id');
        $user = $hrUserId === null ? null : User::query()->where('hr_user_id', $hrUserId)->first();
        $priority = NotificationPriority::tryFrom((string) $this->option('priority'));

        if ($user === null || $priority === null) {
            $this->error(__('notifications.console.test_invalid'));

            return self::FAILURE;
        }

        $delay = max(0, (int) $this->option('delay'));
        $this->info(__('notifications.console.test_waiting', ['seconds' => $delay]));
        sleep($delay);

        $user->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action,
            module: 'system',
            priority: $priority,
            key: 'notifications.test_message',
            params: [],
            subjectType: null,
            subjectId: null,
            link: ['route' => 'notification-settings.edit'],
        )));

        $this->info(__('notifications.console.test_sent', ['name' => $user->name]));

        return self::SUCCESS;
    }
}
