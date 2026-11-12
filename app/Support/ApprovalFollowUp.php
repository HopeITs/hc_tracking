<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Closure;

/**
 * The work a donor approval already decided, done later on its authority
 * (W-34a slice 3, user decision 2026-11-12).
 *
 * A program request linked after its proposal was approved still owes the
 * row its projects. The admin who links it holds `programs.update`, not
 * `proposals.approve` or `projects.create`, so while run() executes, the
 * gate lets that same user through only what the approval settled:
 * approving projects from a proposal, updating this proposal's links, and
 * creating its draft projects. Nothing else is granted, nobody else is
 * granted it, and the grant ends with the callback. Activity is still
 * recorded under the linking user.
 */
final class ApprovalFollowUp
{
    private ?int $proposalId = null;

    private ?int $userId = null;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function run(Proposal $proposal, User $user, Closure $work): mixed
    {
        $this->proposalId = (int) $proposal->getKey();
        $this->userId = (int) $user->getKey();

        try {
            return $work();
        } finally {
            $this->proposalId = null;
            $this->userId = null;
        }
    }

    /**
     * The Gate::before answer: true for a settled ability, null to let the
     * policies decide.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function grants(User $user, string $ability, array $arguments): ?bool
    {
        if ($this->proposalId === null || (int) $user->getKey() !== $this->userId) {
            return null;
        }

        $subject = $arguments[0] ?? null;

        $isSettled = match (true) {
            $subject instanceof Proposal && $ability === 'approve' => $subject->status->isApprovedByDonor(),
            $subject instanceof Proposal && $ability === 'update' => (int) $subject->getKey() === $this->proposalId,
            $subject === Project::class && $ability === 'create' => true,
            default => false,
        };

        return $isSettled ? true : null;
    }
}
