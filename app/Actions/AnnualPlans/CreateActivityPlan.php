<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\ActivityPlan;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProposalFramework;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Start the annual plan of one program in one proposal (step 1 of the
 * workflow). The program must run in the proposal (one of its centres'
 * programs) and the user must plan for it. One plan per proposal and
 * program: an existing one is returned instead of a second.
 */
final class CreateActivityPlan
{
    public function handle(User $user, int $proposalId, int $programId): ActivityPlan
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        $program = Program::query()->select(['id', 'manager_id'])->findOrFail($programId);
        Gate::forUser($user)->authorize('create', [ActivityPlan::class, $program]);

        if (! in_array($program->id, ProposalFramework::programIds($proposal), true)) {
            throw ValidationException::withMessages(['program' => __('annual_plans.program_not_in_proposal')]);
        }

        return DB::transaction(function () use ($user, $proposal, $program): ActivityPlan {
            $existing = ActivityPlan::query()->where('proposal_id', $proposal->id)->where('program_id', $program->id)->lockForUpdate()->first();

            return $existing ?? ActivityPlan::query()->create([
                'proposal_id' => $proposal->id,
                'program_id' => $program->id,
                'created_by' => $user->id,
                'step' => 1,
            ]);
        });
    }
}
