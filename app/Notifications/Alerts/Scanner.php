<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Carbon\CarbonImmutable;

/**
 * One scheduled alert rule. Returns how many notifications it sent.
 */
interface Scanner
{
    public function scan(CarbonImmutable $now): int;
}
