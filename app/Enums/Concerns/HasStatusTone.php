<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use App\Enums\StatusTone;

/**
 * A status-like enum that maps each case onto one shared StatusTone, so every
 * badge, pill and marker colours it the same way app-wide (W-28).
 *
 * Implement tone() as an exhaustive match with no default arm: a new case
 * must declare its tone, the same way ProposalStatus::phase() is exhaustive.
 */
interface HasStatusTone
{
    /**
     * The visual tone for this case.
     */
    public function tone(): StatusTone;
}
