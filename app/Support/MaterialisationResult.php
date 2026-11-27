<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What materialising a proposal's location rows did, or would do
 * (contracts.md §6, W-34a slice 2, Y-17).
 *
 * Y-17: nothing is created or linked any more. Approving a proposal
 * completes its rows, so the three lists are rows: the ones that reached
 * `approved`, the ones still waiting for their responsible to acknowledge,
 * and the ones waiting for a program. preview() fills the same lists from a
 * read, before anything is written, and callers read counts off them.
 */
final readonly class MaterialisationResult
{
    /**
     * @param  list<int>  $approvedLocationIds
     * @param  list<int>  $waitingForOwnerLocationIds
     * @param  list<int>  $waitingForProgramLocationIds
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $approvedLocationIds,
        public array $waitingForOwnerLocationIds,
        public array $waitingForProgramLocationIds,
        public array $warnings,
    ) {}
}
