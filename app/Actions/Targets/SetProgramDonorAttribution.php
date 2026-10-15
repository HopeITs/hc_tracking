<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Enums\DonorAttribution;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Set how a program combines one indicator's targets across donors
 * (contracts §12.4, D-40/D-46). Held by `report-templates.manage` (MEAL);
 * the change is logged on the program as `program.updated`.
 */
final class SetProgramDonorAttribution
{
    public function handle(Program $program, DonorAttribution $attribution): Program
    {
        Gate::authorize('report-templates.manage');

        return DB::transaction(function () use ($program, $attribution): Program {
            $locked = Program::query()->whereKey($program->getKey())->lockForUpdate()->firstOrFail();
            $locked->fill(['donor_attribution' => $attribution])->save();

            return $locked;
        });
    }
}
