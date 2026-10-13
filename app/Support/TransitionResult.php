<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProposalStatusNote;

/**
 * What a proposal status move produced (contracts.md §6): the audit note it
 * wrote, and warning keys (no_agreement, all_projects_archived, reviewers_unseen).
 */
final readonly class TransitionResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ProposalStatusNote $note,
        public array $warnings,
    ) {}
}
