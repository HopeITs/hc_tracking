<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Locale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The security email sent to the administrators when refused sign-in
 * attempts pile up (see AlertOnLockoutSurge): the sign of a password
 * guessing run rather than one person's typo.
 *
 * It is sent from the refused request, so it queues itself to keep a slow
 * mail server off the response. The language follows the administrator's
 * preferred locale (HasLocalePreference); the published mail layout turns
 * Arabic mail right to left.
 */
final class LockoutSurge extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $attempts,
        public int $minutes,
        public string $latestIpAddress,
        public CarbonImmutable $noticedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Written in the language the notification is being sent in.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $name = $notifiable->name;
        $time = Locale::current()->formatDate($this->noticedAt, (string) __('formats.datetime'));

        $message = (new MailMessage)
            ->subject(__('security.lockout_surge_subject', ['app' => config('app.name')]))
            ->line(__('security.lockout_surge_intro', ['attempts' => $this->attempts, 'minutes' => $this->minutes]))
            ->line(__('security.new_sign_in_when', ['time' => $time, 'timezone' => $this->noticedAt->timezoneName]))
            ->line(__('security.lockout_surge_latest_ip', ['ip' => $this->latestIpAddress]))
            ->line(__('security.lockout_surge_what_it_means'))
            ->line(__('security.lockout_surge_what_to_do'));

        return $name === '' ? $message : $message->greeting(__('security.new_sign_in_greeting', ['name' => $name]));
    }
}
