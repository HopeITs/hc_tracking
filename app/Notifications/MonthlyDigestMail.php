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
 * The monthly digest email (App\Notifications\Alerts\MonthlyDigestScanner).
 * Mail only: the in-app copy is a separate AppNotification, so a retried
 * mail never repeats it. Counts and names only, never an amount. Sent in the
 * reader's language (User::preferredLocale()).
 *
 * @phpstan-import-type Sections from \App\Notifications\Alerts\MonthlyDigestScanner
 */
final class MonthlyDigestMail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  string  $month  YYYY-MM
     * @param  Sections  $sections
     */
    public function __construct(
        public string $month,
        public bool $allPrograms,
        public array $sections,
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
        $monthLabel = (Locale::tryFrom(app()->getLocale()) ?? Locale::English)
            ->formatDate(CarbonImmutable::parse($this->month.'-01'), __('notifications.digests.monthly.month_format'));

        return (new MailMessage)
            ->subject(__('notifications.mail.monthly_digest.subject', ['month' => $monthLabel]))
            ->markdown('mail.monthly-digest', [
                'monthLabel' => $monthLabel,
                'allPrograms' => $this->allPrograms,
                'sections' => $this->sections,
                'nothingPending' => array_sum(array_map('array_sum', $this->sections)) === 0,
            ]);
    }
}
