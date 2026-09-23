<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * Strategies: watched by Managers.
 */
final class StrategyRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Managers'];
    }
}
