<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\User;
use LogicException;

/**
 * Create or update a proposal from validated form data.
 *
 * Stub with the frozen contracts.md §6 signature; T06 implements it.
 */
final class SaveProposal
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Proposal $existing, array $data, User $actor): Proposal
    {
        throw new LogicException('Implemented in T06');
    }
}
