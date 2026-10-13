<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What approving a proposal did to its linked projects (contracts.md §6).
 */
final readonly class ApprovalResult
{
    /**
     * @param  list<int>  $approvedProjectIds
     * @param  list<int>  $waitingProjectIds
     * @param  list<int>  $skippedProjectIds
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $approvedProjectIds,
        public array $waitingProjectIds,
        public array $skippedProjectIds,
        public array $warnings,
    ) {}
}
