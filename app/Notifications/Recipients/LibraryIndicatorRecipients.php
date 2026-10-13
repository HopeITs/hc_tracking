<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

/**
 * The Indicator Library: watched by MEAL, who own it.
 */
final class LibraryIndicatorRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['MEAL'];
    }
}
