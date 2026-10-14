<?php

declare(strict_types=1);

namespace App\Actions\Sectors;

use App\Models\Sector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Take a sector off new selection. It stays on every project that already
 * has it, shown with an "Inactive" badge.
 */
final class DeactivateSector
{
    /**
     * @throws ValidationException when the sector is already inactive (someone else got there first)
     */
    public function handle(int $sectorId): Sector
    {
        return DB::transaction(function () use ($sectorId): Sector {
            $sector = Sector::query()->lockForUpdate()->findOrFail($sectorId);

            Gate::authorize('update', $sector);

            if (! $sector->is_active) {
                throw ValidationException::withMessages(['sector' => __('sectors.stale')]);
            }

            $sector->forceFill(['is_active' => false])->save();

            return $sector;
        });
    }
}
