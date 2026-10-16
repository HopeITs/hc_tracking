<?php

declare(strict_types=1);

namespace App\Actions\Strategies;

use App\Models\StrategicPillar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a strategic pillar no program sits under. The check runs on the
 * locked pillar row, and AssignProgramToPillar locks the same row, so a
 * program cannot slip under the pillar while it is being deleted.
 */
final class DeleteStrategicPillar
{
    /**
     * @throws ValidationException when any program is assigned to the pillar
     */
    public function handle(int $pillarId): void
    {
        DB::transaction(function () use ($pillarId): void {
            $pillar = StrategicPillar::query()->lockForUpdate()->find($pillarId);

            if ($pillar === null) {
                return;
            }

            Gate::authorize('delete', $pillar);

            if ($pillar->programs()->exists()) {
                throw ValidationException::withMessages(['pillar' => __('strategies.pillar_has_programs')]);
            }

            $pillar->delete();
        });
    }
}
