<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ProposalLocation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An active location row's dates grew (contracts.md §5, Y-17). Replaces
 * ProjectPeriodChanged; the notification key `project.period_changed` keeps
 * its name (wave4/AGENT.md §8.5).
 */
final class LocationPeriodChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ProposalLocation $row,
        public readonly CarbonImmutable $oldStart,
        public readonly CarbonImmutable $oldEnd,
        public readonly CarbonImmutable $newStart,
        public readonly CarbonImmutable $newEnd,
    ) {}
}
