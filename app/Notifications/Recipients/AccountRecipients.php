<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * Accounts: watched by Financial.
 */
final class AccountRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial'];
    }
}
