<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Locale;
use App\Http\UserAgent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The security email sent when someone signs in from a browser/platform the
 * user has not signed in from before (see RecordSignInDevice).
 *
 * Mail only, never part of the in-app inbox. It is sent from the login
 * request, so it queues itself to keep a slow mail server off the sign-in.
 * The language follows the user's preferred locale (HasLocalePreference);
 * the published mail layout (resources/views/vendor/mail/html/layout.blade.php)
 * turns Arabic mail right to left.
 */
final class NewDeviceSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public UserAgent $device,
        public string $ipAddress,
        public CarbonImmutable $signedInAt,
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
        $time = Locale::current()->formatDate($this->signedInAt, (string) __('formats.datetime'));

        $message = (new MailMessage)
            ->subject(__('security.new_sign_in_subject', ['app' => config('app.name')]))
            ->line(__('security.new_sign_in_intro'))
            ->line(__('security.new_sign_in_when', ['time' => $time, 'timezone' => $this->signedInAt->timezoneName]))
            ->line(__('security.new_sign_in_device', ['device' => $this->deviceName()]))
            ->line(__('security.new_sign_in_ip', ['ip' => $this->ipAddress]))
            ->line(__('security.new_sign_in_if_it_was_you'))
            ->line(__('security.new_sign_in_if_it_was_not_you'))
            ->action(__('security.new_sign_in_action'), route('security.edit'));

        return $name === '' ? $message : $message->greeting(__('security.new_sign_in_greeting', ['name' => $name]));
    }

    /**
     * The device in the current language, saying "unrecognised" rather than
     * the untranslated "Unknown" for a browser or platform UserAgent could
     * not tell.
     */
    private function deviceName(): string
    {
        $knowsBrowser = $this->device->browser !== UserAgent::Unknown;
        $knowsPlatform = $this->device->platform !== UserAgent::Unknown;

        return match (true) {
            $knowsBrowser && $knowsPlatform => $this->device->label(),
            $knowsBrowser => __('security.browser_on_unrecognised_platform', ['browser' => $this->device->browser]),
            $knowsPlatform => __('security.unrecognised_browser_on_platform', ['platform' => $this->device->platform]),
            default => __('security.unrecognised_device'),
        };
    }
}
