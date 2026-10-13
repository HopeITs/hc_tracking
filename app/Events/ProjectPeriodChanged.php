<?php

declare(strict_types=1);

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An active project's dates changed because another of its proposals was
 * approved (contracts.md §5).
 */
final class ProjectPeriodChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $projectId,
        public readonly CarbonImmutable $oldStart,
        public readonly CarbonImmutable $oldEnd,
        public readonly CarbonImmutable $newStart,
        public readonly CarbonImmutable $newEnd,
    ) {}
}
