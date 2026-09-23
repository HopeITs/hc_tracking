<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * Donors: watched by Financial and the Public Relations Manager.
 */
final class DonorRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }
}
