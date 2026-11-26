<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What approving a proposal did to its location rows (contracts.md §6).
 *
 * Y-17: the three lists carry **row** ids, not project ids — a proposal
 * delivers at places now, and a place is one row of one proposal (D-93).
 * The outcome keys are unchanged: funded, waiting, extended, skipped.
 */
final readonly class ApprovalResult
{
    /**
     * @param  list<int>  $approvedLocationIds
     * @param  list<int>  $waitingLocationIds
     * @param  list<int>  $skippedLocationIds
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $approvedLocationIds,
        public array $waitingLocationIds,
        public array $skippedLocationIds,
        public array $warnings,
    ) {}
}
