<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time sign-in code emailed during login when the user has turned on
 * email two-factor authentication (see App\Actions\Fortify\EmailLoginCodes).
 *
 * Sent immediately (never queued): the person is waiting on the login page.
 * Mail only, never part of the in-app inbox.
 */
final class EmailLoginCode extends Notification
{
    public function __construct(
        #[\SensitiveParameter]
        public string $code,
        public int $minutesValid,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('security.login_code_subject', ['app' => config('app.name')]))
            ->line(__('security.login_code_intro'))
            ->line('**'.$this->code.'**')
            ->line(__('security.login_code_expires', ['minutes' => $this->minutesValid]))
            ->line(__('security.login_code_if_it_was_not_you'));
    }
}
