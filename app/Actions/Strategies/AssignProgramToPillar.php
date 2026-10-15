<?php

declare(strict_types=1);

namespace App\Actions\Strategies;

use App\Models\Program;
use App\Models\StrategicPillar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Put a program under a strategic pillar, or take it out (null). The pillar
 * must belong to the program's strategy; a program without a strategy takes
 * the pillar's (D-35). Needs `strategies.update`.
 */
final class AssignProgramToPillar
{
    /**
     * @throws ValidationException when the pillar belongs to another strategy
     */
    public function handle(int $programId, ?int $pillarId): Program
    {
        if ($pillarId === null) {
            Gate::authorize('strategies.update');
        } else {
            Gate::authorize('update', StrategicPillar::query()->findOrFail($pillarId));
        }

        return DB::transaction(function () use ($programId, $pillarId): Program {
            $pillar = $pillarId === null ? null : StrategicPillar::query()->lockForUpdate()->findOrFail($pillarId);
            $program = Program::query()->lockForUpdate()->findOrFail($programId);

            if ($pillar !== null && $program->strategy_id !== null && $program->strategy_id !== $pillar->strategy_id) {
                throw ValidationException::withMessages(['pillar_id' => __('strategies.pillar_other_strategy')]);
            }

            if ($program->strategic_pillar_id === $pillar?->id) {
                return $program;
            }

            $program->strategic_pillar_id = $pillar?->id;

            if ($pillar !== null) {
                $program->strategy_id ??= $pillar->strategy_id;
            }

            $program->save();

            return $program;
        });
    }
}
