<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * Results: watched by MEAL.
 */
final class ResultRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['MEAL'];
    }
}
